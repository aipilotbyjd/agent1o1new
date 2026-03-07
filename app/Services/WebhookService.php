<?php

namespace App\Services;

use App\Enums\ExecutionMode;
use App\Exceptions\ApiException;
use App\Models\Webhook;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Support\Str;

class WebhookService
{
    public function __construct(private ExecutionService $executionService) {}

    /**
     * Create a webhook for a workflow.
     *
     * @param  array{path?: string, methods?: array, auth_type?: string, auth_config?: array, rate_limit?: int, response_mode?: string, response_status?: int, response_body?: array}  $data
     */
    public function create(Workspace $workspace, Workflow $workflow, array $data): Webhook
    {
        if ($workflow->webhooks()->exists()) {
            throw ApiException::conflict('This workflow already has a webhook. Each workflow can only have one webhook.');
        }

        return Webhook::create([
            'workflow_id' => $workflow->id,
            'workspace_id' => $workspace->id,
            'uuid' => (string) Str::uuid(),
            'path' => $data['path'] ?? null,
            'methods' => $data['methods'] ?? ['POST'],
            'is_active' => true,
            'auth_type' => $data['auth_type'] ?? 'none',
            'auth_config' => isset($data['auth_config']) ? json_encode($data['auth_config']) : null,
            'rate_limit' => $data['rate_limit'] ?? null,
            'response_mode' => $data['response_mode'] ?? 'immediate',
            'response_status' => $data['response_status'] ?? 200,
            'response_body' => $data['response_body'] ?? null,
        ]);
    }

    /**
     * Update a webhook.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Webhook $webhook, array $data): Webhook
    {
        if (isset($data['auth_config'])) {
            $data['auth_config'] = json_encode($data['auth_config']);
        }

        $webhook->update($data);

        return $webhook;
    }

    /**
     * Delete a webhook.
     */
    public function delete(Webhook $webhook): void
    {
        $webhook->delete();
    }

    /**
     * Handle an incoming webhook call from a third-party service.
     *
     * @return array{execution_id: int|null, status: string, response_status: int, response_body: mixed}
     */
    public function handleIncoming(Webhook $webhook, string $method, array $payload, array $headers): array
    {
        if (! $webhook->is_active) {
            return [
                'execution_id' => null,
                'status' => 'inactive',
                'response_status' => 410,
                'response_body' => ['error' => 'Webhook is inactive.'],
            ];
        }

        $workflow = $webhook->workflow;

        if (! $workflow || ! $workflow->is_active) {
            return [
                'execution_id' => null,
                'status' => 'workflow_inactive',
                'response_status' => 410,
                'response_body' => ['error' => 'Workflow is inactive.'],
            ];
        }

        // Validate HTTP method
        $allowedMethods = array_map('strtoupper', $webhook->methods ?? ['POST']);
        if (! in_array(strtoupper($method), $allowedMethods)) {
            return [
                'execution_id' => null,
                'status' => 'method_not_allowed',
                'response_status' => 405,
                'response_body' => ['error' => 'Method not allowed.'],
            ];
        }

        // Validate auth
        if (! $this->verifyAuth($webhook, $headers)) {
            return [
                'execution_id' => null,
                'status' => 'unauthorized',
                'response_status' => 401,
                'response_body' => ['error' => 'Unauthorized.'],
            ];
        }

        // Update call stats
        $webhook->increment('call_count');
        $webhook->update(['last_called_at' => now()]);

        // Trigger execution
        $triggerData = [
            'webhook_uuid' => $webhook->uuid,
            'method' => $method,
            'headers' => $headers,
            'body' => $payload,
        ];

        try {
            $execution = $this->executionService->trigger(
                $workflow,
                $workflow->creator ?? $webhook->workspace->owner,
                $triggerData,
                ExecutionMode::Webhook,
            );

            return [
                'execution_id' => $execution->id,
                'status' => 'triggered',
                'response_status' => $webhook->response_status,
                'response_body' => $webhook->response_body ?? ['success' => true, 'execution_id' => $execution->id],
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Webhook trigger failed: ' . $e->getMessage(), [
                'exception' => $e,
                'webhook_uuid' => $webhook->uuid,
                'workflow_id' => $workflow->id,
            ]);

            return [
                'execution_id' => null,
                'status' => 'error',
                'response_status' => 500,
                'response_body' => ['error' => 'Failed to trigger execution.'],
            ];
        }
    }

    /**
     * Verify authentication for an incoming webhook request.
     */
    private function verifyAuth(Webhook $webhook, array $headers): bool
    {
        if ($webhook->auth_type === 'none') {
            return true;
        }

        $config = json_decode($webhook->auth_config ?? '{}', true) ?? [];

        return match ($webhook->auth_type) {
            'bearer' => $this->verifyBearerAuth($config, $headers),
            'basic' => $this->verifyBasicAuth($config, $headers),
            'header' => $this->verifyHeaderAuth($config, $headers),
            default => false,
        };
    }

    private function verifyBearerAuth(array $config, array $headers): bool
    {
        $expected = $config['token'] ?? '';
        $authorization = $headers['authorization'] ?? $headers['Authorization'] ?? '';

        if (str_starts_with($authorization, 'Bearer ')) {
            $token = substr($authorization, 7);

            return hash_equals($expected, $token);
        }

        return false;
    }

    private function verifyBasicAuth(array $config, array $headers): bool
    {
        $expectedUser = $config['username'] ?? '';
        $expectedPass = $config['password'] ?? '';
        $authorization = $headers['authorization'] ?? $headers['Authorization'] ?? '';

        if (str_starts_with($authorization, 'Basic ')) {
            $decoded = base64_decode(substr($authorization, 6));
            [$user, $pass] = explode(':', $decoded, 2) + [1 => ''];

            return hash_equals($expectedUser, $user) && hash_equals($expectedPass, $pass);
        }

        return false;
    }

    private function verifyHeaderAuth(array $config, array $headers): bool
    {
        $headerName = strtolower($config['header_name'] ?? '');
        $expectedValue = $config['header_value'] ?? '';

        $normalizedHeaders = array_change_key_case($headers);
        $actualValue = $normalizedHeaders[$headerName] ?? '';

        return hash_equals($expectedValue, $actualValue);
    }
}
