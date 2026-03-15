<?php

namespace App\Http\Controllers\Api\V1;

use App\Engine\WebhookRegistrars\WebhookRegistrarRegistry;
use App\Models\Webhook;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookReceiverController
{
    public function __construct(private WebhookService $webhookService) {}

    /**
     * Handle an incoming public webhook call.
     */
    public function handle(Request $request, string $uuid): JsonResponse
    {
        $webhook = Webhook::query()->where('uuid', $uuid)->first();

        if (! $webhook) {
            return response()->json(['error' => 'Webhook not found.'], 404);
        }

        if ($webhook->isExternallyManaged() && ! $this->verifyProviderSignature($request, $webhook)) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $headers = array_map(
            fn ($values) => is_array($values) ? ($values[0] ?? '') : $values,
            $request->headers->all(),
        );

        $result = $this->webhookService->handleIncoming(
            $webhook,
            $request->method(),
            $request->all(),
            $headers,
        );

        return response()->json(
            $result['response_body'],
            $result['response_status'],
        );
    }

    /**
     * Verify the webhook signature using the provider's registrar.
     */
    private function verifyProviderSignature(Request $request, Webhook $webhook): bool
    {
        $registrar = WebhookRegistrarRegistry::resolve($webhook->provider);

        if (! $registrar) {
            return true;
        }

        $payload = $request->getContent();
        $secret = $webhook->external_webhook_secret;

        if (! $secret) {
            return true;
        }

        $signature = match ($webhook->provider) {
            'stripe' => $request->header('Stripe-Signature', ''),
            'github' => $request->header('X-Hub-Signature-256', ''),
            default => '',
        };

        return $registrar->verifySignature($payload, $signature, $secret);
    }
}
