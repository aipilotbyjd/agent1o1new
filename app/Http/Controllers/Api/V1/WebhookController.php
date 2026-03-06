<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Webhook\StoreWebhookRequest;
use App\Http\Requests\Api\V1\Webhook\UpdateWebhookRequest;
use App\Http\Resources\Api\V1\WebhookResource;
use App\Models\Webhook;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private WebhookService $webhookService) {}

    /**
     * List all webhooks in a workspace.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::WebhookView);

        $query = $workspace->webhooks()->with('workflow');

        if ($request->filled('workflow_id')) {
            $query->where('workflow_id', $request->integer('workflow_id'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $query->orderByDesc('created_at');

        $perPage = min((int) $request->input('per_page', 15), self::MAX_PER_PAGE);
        $webhooks = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Webhooks retrieved successfully.',
            WebhookResource::collection($webhooks),
        );
    }

    /**
     * Create a webhook for a workflow.
     */
    public function store(StoreWebhookRequest $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $webhook = $this->webhookService->create(
            $workspace,
            $workflow,
            $request->validated(),
        );

        $webhook->load('workflow');

        return $this->successResponse(
            'Webhook created successfully.',
            new WebhookResource($webhook),
            201,
        );
    }

    /**
     * Show a single webhook.
     */
    public function show(Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->can(Permission::WebhookView);

        $webhook->load('workflow');

        return $this->successResponse(
            'Webhook retrieved successfully.',
            new WebhookResource($webhook),
        );
    }

    /**
     * Update a webhook.
     */
    public function update(UpdateWebhookRequest $request, Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $webhook = $this->webhookService->update($webhook, $request->validated());
        $webhook->load('workflow');

        return $this->successResponse(
            'Webhook updated successfully.',
            new WebhookResource($webhook),
        );
    }

    /**
     * Delete a webhook.
     */
    public function destroy(Workspace $workspace, Webhook $webhook): JsonResponse
    {
        $this->can(Permission::WebhookDelete);

        $this->webhookService->delete($webhook);

        return $this->successResponse('Webhook deleted successfully.');
    }
}
