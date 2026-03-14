<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PollingTrigger\StorePollingTriggerRequest;
use App\Http\Requests\Api\V1\PollingTrigger\UpdatePollingTriggerRequest;
use App\Http\Resources\Api\V1\PollingTriggerResource;
use App\Models\PollingTrigger;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\PollingTriggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PollingTriggerController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private PollingTriggerService $pollingTriggerService) {}

    /**
     * List all polling triggers in a workspace.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::PollingTriggerView);

        $query = $workspace->pollingTriggers()->with('workflow');

        if ($request->filled('workflow_id')) {
            $query->where('workflow_id', $request->integer('workflow_id'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $query->orderByDesc('created_at');

        $perPage = min((int) $request->input('per_page', 15), self::MAX_PER_PAGE);
        $pollingTriggers = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Polling triggers retrieved successfully.',
            PollingTriggerResource::collection($pollingTriggers),
        );
    }

    /**
     * Create a polling trigger for a workflow.
     */
    public function store(StorePollingTriggerRequest $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $pollingTrigger = $this->pollingTriggerService->create(
            $workspace,
            $workflow,
            $request->validated(),
        );

        $pollingTrigger->load('workflow');

        return $this->successResponse(
            'Polling trigger created successfully.',
            new PollingTriggerResource($pollingTrigger),
            201,
        );
    }

    /**
     * Show a single polling trigger.
     */
    public function show(Workspace $workspace, PollingTrigger $pollingTrigger): JsonResponse
    {
        $this->can(Permission::PollingTriggerView);

        $pollingTrigger->load('workflow');

        return $this->successResponse(
            'Polling trigger retrieved successfully.',
            new PollingTriggerResource($pollingTrigger),
        );
    }

    /**
     * Update a polling trigger.
     */
    public function update(UpdatePollingTriggerRequest $request, Workspace $workspace, PollingTrigger $pollingTrigger): JsonResponse
    {
        $pollingTrigger = $this->pollingTriggerService->update($pollingTrigger, $request->validated());
        $pollingTrigger->load('workflow');

        return $this->successResponse(
            'Polling trigger updated successfully.',
            new PollingTriggerResource($pollingTrigger),
        );
    }

    /**
     * Delete a polling trigger.
     */
    public function destroy(Workspace $workspace, PollingTrigger $pollingTrigger): JsonResponse
    {
        $this->can(Permission::PollingTriggerDelete);

        $this->pollingTriggerService->delete($pollingTrigger);

        return $this->successResponse('Polling trigger deleted successfully.');
    }
}
