<?php

namespace App\Services;

use App\Engine\WebhookRegistrars\WebhookRegistrarRegistry;
use App\Models\Webhook;
use App\Models\Workflow;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles automatic webhook registration/unregistration with external
 * services (Stripe, GitHub, etc.) when workflows are activated or deactivated.
 */
class WebhookAutoRegistrationService
{
    /**
     * Register external webhooks for all trigger nodes that support it.
     *
     * Called when a workflow is activated. Scans the workflow's current version
     * nodes for trigger types with a known provider, then creates the webhook
     * on both the external service and locally.
     */
    public function registerForWorkflow(Workflow $workflow): void
    {
        $triggerNodes = $this->extractTriggerNodes($workflow);

        foreach ($triggerNodes as $node) {
            $provider = $node['provider'] ?? null;

            if (! $provider || ! WebhookRegistrarRegistry::supports($provider)) {
                continue;
            }

            $this->registerExternalWebhook($workflow, $node, $provider);
        }
    }

    /**
     * Unregister all external webhooks for a workflow.
     *
     * Called when a workflow is deactivated. Finds all webhooks with an
     * external provider and calls the provider API to delete them.
     */
    public function unregisterForWorkflow(Workflow $workflow): void
    {
        $webhooks = $workflow->webhooks()
            ->whereNotNull('provider')
            ->whereNotNull('external_webhook_id')
            ->get();

        foreach ($webhooks as $webhook) {
            $this->unregisterExternalWebhook($webhook);
        }
    }

    /**
     * Register a single external webhook for a trigger node.
     *
     * @param  array<string, mixed>  $node
     */
    private function registerExternalWebhook(Workflow $workflow, array $node, string $provider): void
    {
        $registrar = WebhookRegistrarRegistry::resolve($provider);

        if (! $registrar) {
            return;
        }

        $credentials = $this->resolveCredentials($workflow, $node);
        $nodeId = $node['id'] ?? null;

        if (! $credentials) {
            Log::warning('No credentials found for webhook auto-registration', [
                'workflow_id' => $workflow->id,
                'provider' => $provider,
                'node_id' => $nodeId,
            ]);

            return;
        }

        $existingWebhook = $workflow->webhooks()
            ->where('node_id', $nodeId)
            ->where('provider', $provider)
            ->first();

        if ($existingWebhook && $existingWebhook->external_webhook_id) {
            if ($registrar->checkExists($existingWebhook->external_webhook_id, $credentials, $existingWebhook->provider_config ?? [])) {
                return;
            }

            $existingWebhook->update([
                'external_webhook_id' => null,
                'external_webhook_secret' => null,
            ]);
        }

        $uuid = $existingWebhook?->uuid ?? (string) Str::uuid();
        $callbackUrl = $this->buildCallbackUrl($uuid);
        $events = $node['events'] ?? $node['config']['events'] ?? ['*'];
        $providerConfig = $this->buildProviderConfig($node, $provider);

        try {
            $result = $registrar->register($callbackUrl, $events, $credentials, $providerConfig);

            if ($existingWebhook) {
                $existingWebhook->update([
                    'external_webhook_id' => $result['external_id'],
                    'external_webhook_secret' => $result['secret'],
                    'provider_config' => $providerConfig,
                ]);
            } else {
                Webhook::create([
                    'workflow_id' => $workflow->id,
                    'workspace_id' => $workflow->workspace_id,
                    'uuid' => $uuid,
                    'node_id' => $nodeId,
                    'provider' => $provider,
                    'external_webhook_id' => $result['external_id'],
                    'external_webhook_secret' => $result['secret'],
                    'provider_config' => $providerConfig,
                    'methods' => ['POST'],
                    'is_active' => true,
                    'auth_type' => 'none',
                    'response_mode' => 'immediate',
                    'response_status' => 200,
                ]);
            }

            Log::info('External webhook registered', [
                'workflow_id' => $workflow->id,
                'provider' => $provider,
                'node_id' => $nodeId,
                'external_id' => $result['external_id'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to register external webhook', [
                'workflow_id' => $workflow->id,
                'provider' => $provider,
                'node_id' => $nodeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Unregister a single external webhook.
     */
    private function unregisterExternalWebhook(Webhook $webhook): void
    {
        $registrar = WebhookRegistrarRegistry::resolve($webhook->provider);

        if (! $registrar) {
            return;
        }

        $credentials = $this->resolveCredentialsForWebhook($webhook);

        if (! $credentials) {
            Log::warning('No credentials found for webhook unregistration', [
                'webhook_id' => $webhook->id,
                'provider' => $webhook->provider,
            ]);

            return;
        }

        try {
            $registrar->unregister(
                $webhook->external_webhook_id,
                $credentials,
                $webhook->provider_config ?? [],
            );

            $webhook->update([
                'external_webhook_id' => null,
                'external_webhook_secret' => null,
            ]);

            Log::info('External webhook unregistered', [
                'webhook_id' => $webhook->id,
                'provider' => $webhook->provider,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to unregister external webhook', [
                'webhook_id' => $webhook->id,
                'provider' => $webhook->provider,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract trigger nodes from the workflow's current version that have a provider.
     *
     * @return list<array<string, mixed>>
     */
    private function extractTriggerNodes(Workflow $workflow): array
    {
        $workflow->loadMissing('currentVersion');

        $version = $workflow->currentVersion;

        if (! $version) {
            return [];
        }

        $nodes = $version->nodes ?? [];
        $triggers = [];

        foreach ($nodes as $node) {
            $type = $node['type'] ?? '';
            $config = $node['config'] ?? [];
            $triggerType = $config['trigger_type'] ?? null;

            if ($type !== 'trigger' || ! $triggerType) {
                continue;
            }

            if (WebhookRegistrarRegistry::supports($triggerType)) {
                $triggers[] = [
                    'id' => $node['id'] ?? null,
                    'provider' => $triggerType,
                    'events' => $config['events'] ?? ['*'],
                    'config' => $config,
                    'credential_id' => $node['credential_id'] ?? $config['credential_id'] ?? null,
                ];
            }
        }

        return $triggers;
    }

    /**
     * Resolve credentials for a trigger node from the workflow.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function resolveCredentials(Workflow $workflow, array $node): ?array
    {
        $credentialId = $node['credential_id'] ?? null;

        if (! $credentialId) {
            $credential = $workflow->credentials()
                ->wherePivot('node_id', $node['id'] ?? '')
                ->first();
        } else {
            $credential = $workflow->credentials()->find($credentialId);
        }

        if (! $credential) {
            return null;
        }

        $data = $credential->data;

        return is_array($data) ? $data : json_decode($data, true);
    }

    /**
     * Resolve credentials for an existing webhook's workflow.
     *
     * @return array<string, mixed>|null
     */
    private function resolveCredentialsForWebhook(Webhook $webhook): ?array
    {
        $webhook->loadMissing('workflow.credentials');

        $workflow = $webhook->workflow;

        if (! $workflow) {
            return null;
        }

        $providerConfig = $webhook->provider_config ?? [];
        $nodeId = $providerConfig['node_id'] ?? null;

        if ($nodeId) {
            $credential = $workflow->credentials()
                ->wherePivot('node_id', $nodeId)
                ->first();
        } else {
            $credential = $workflow->credentials
                ->first(fn ($c) => str_contains(strtolower($c->type ?? ''), $webhook->provider));
        }

        if (! $credential) {
            return null;
        }

        $data = $credential->data;

        return is_array($data) ? $data : json_decode($data, true);
    }

    /**
     * Build the callback URL for the webhook receiver endpoint.
     */
    private function buildCallbackUrl(string $uuid): string
    {
        $baseUrl = config('app.url');

        return rtrim($baseUrl, '/').'/api/v1/webhook/'.$uuid;
    }

    /**
     * Build provider-specific config from the trigger node.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function buildProviderConfig(array $node, string $provider): array
    {
        $config = $node['config'] ?? [];
        $base = ['node_id' => $node['id'] ?? null];

        return match ($provider) {
            'github' => [
                ...$base,
                'owner' => $config['owner'] ?? '',
                'repository' => $config['repository'] ?? '',
            ],
            'stripe' => [
                ...$base,
                'events' => $config['events'] ?? ['*'],
            ],
            default => $base,
        };
    }
}
