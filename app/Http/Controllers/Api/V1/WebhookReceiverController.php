<?php

namespace App\Http\Controllers\Api\V1;

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
}
