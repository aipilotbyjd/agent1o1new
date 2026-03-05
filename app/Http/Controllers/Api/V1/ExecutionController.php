<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Execution\TriggerExecutionRequest;
use App\Http\Resources\Api\V1\ExecutionLogResource;
use App\Http\Resources\Api\V1\ExecutionNodeResource;
use App\Http\Resources\Api\V1\ExecutionResource;
use App\Models\Execution;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\ExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutionController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private ExecutionService $executionService) {}

    /**
     * List all executions in a workspace.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::ExecutionView);

        $query = $workspace->executions()
            ->with(['workflow', 'triggeredBy'])
            ->withCount('nodes');

        if ($request->filled('status')) {
            $status = ExecutionStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->byStatus($status);
            }
        }

        if ($request->filled('workflow_id')) {
            $query->where('workflow_id', $request->integer('workflow_id'));
        }

        if ($request->filled('mode')) {
            $mode = ExecutionMode::tryFrom($request->input('mode'));
            if ($mode) {
                $query->where('mode', $mode);
            }
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        $query->orderByDesc('created_at');

        $perPage = min((int) $request->input('per_page', 15), self::MAX_PER_PAGE);
        $executions = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Executions retrieved successfully.',
            ExecutionResource::collection($executions),
        );
    }

    /**
     * Trigger a manual workflow execution.
     */
    public function store(TriggerExecutionRequest $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $execution = $this->executionService->trigger(
            $workflow,
            $request->user(),
            $request->validated('trigger_data'),
        );

        $execution->load(['workflow', 'triggeredBy']);

        return $this->successResponse(
            'Execution triggered successfully.',
            new ExecutionResource($execution),
            201,
        );
    }

    /**
     * Show a single execution with its nodes.
     */
    public function show(Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->can(Permission::ExecutionView);

        $execution->load(['workflow', 'triggeredBy', 'nodes']);
        $execution->loadCount('nodes');

        return $this->successResponse(
            'Execution retrieved successfully.',
            new ExecutionResource($execution),
        );
    }

    /**
     * Delete an execution.
     */
    public function destroy(Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->can(Permission::ExecutionDelete);

        $this->executionService->delete($execution);

        return $this->successResponse('Execution deleted successfully.');
    }

    /**
     * List execution nodes.
     */
    public function nodes(Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->can(Permission::ExecutionView);

        $nodes = $execution->nodes()->get();

        return $this->successResponse(
            'Execution nodes retrieved successfully.',
            ExecutionNodeResource::collection($nodes),
        );
    }

    /**
     * List execution logs with optional filters.
     */
    public function logs(Request $request, Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->can(Permission::ExecutionView);

        $query = $execution->logs();

        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        if ($request->filled('execution_node_id')) {
            $query->where('execution_node_id', $request->integer('execution_node_id'));
        }

        $perPage = min((int) $request->input('per_page', 50), self::MAX_PER_PAGE);
        $logs = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Execution logs retrieved successfully.',
            ExecutionLogResource::collection($logs),
        );
    }

    /**
     * Get aggregated execution stats.
     */
    public function stats(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::ExecutionView);

        $workflowId = $request->filled('workflow_id')
            ? $request->integer('workflow_id')
            : null;

        $stats = $this->executionService->stats($workspace, $workflowId);

        return $this->successResponse('Execution stats retrieved successfully.', $stats);
    }

    /**
     * Retry a failed execution.
     */
    public function retry(Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->can(Permission::ExecutionRetry);

        $newExecution = $this->executionService->retry($execution, auth()->user());
        $newExecution->load(['workflow', 'triggeredBy']);

        return $this->successResponse(
            'Execution retry triggered successfully.',
            new ExecutionResource($newExecution),
            201,
        );
    }

    /**
     * Cancel an active execution.
     */
    public function cancel(Workspace $workspace, Execution $execution): JsonResponse
    {
        $this->can(Permission::ExecutionCancel);

        $execution = $this->executionService->cancel($execution);
        $execution->load(['workflow', 'triggeredBy']);

        return $this->successResponse(
            'Execution cancelled successfully.',
            new ExecutionResource($execution),
        );
    }

    /**
     * List executions for a specific workflow.
     */
    public function workflowExecutions(Request $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->can(Permission::ExecutionView);

        $query = $workflow->executions()
            ->with('triggeredBy')
            ->withCount('nodes')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $status = ExecutionStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->byStatus($status);
            }
        }

        $perPage = min((int) $request->input('per_page', 15), self::MAX_PER_PAGE);
        $executions = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Workflow executions retrieved successfully.',
            ExecutionResource::collection($executions),
        );
    }
}
