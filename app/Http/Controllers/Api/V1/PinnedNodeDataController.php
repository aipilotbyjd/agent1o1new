<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PinnedNodeDataResource;
use App\Models\PinnedNodeData;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\PinnedDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PinnedNodeDataController extends Controller
{
    public function __construct(private PinnedDataService $pinnedDataService) {}

    /**
     * List pinned data for a workflow.
     */
    public function index(Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->can(Permission::WorkflowView);

        $pins = PinnedNodeData::where('workflow_id', $workflow->id)
            ->with('pinnedBy')
            ->get();

        return $this->successResponse(
            'Pinned node data retrieved successfully.',
            PinnedNodeDataResource::collection($pins),
        );
    }

    /**
     * Pin data to a node.
     */
    public function store(Request $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->can(Permission::WorkflowUpdate);

        $validated = $request->validate([
            'node_id' => ['required', 'string', 'max:100'],
            'node_name' => ['nullable', 'string', 'max:255'],
            'data' => ['required', 'array'],
        ]);

        $pin = $this->pinnedDataService->pin(
            $workspace,
            $workflow,
            $request->user(),
            $validated,
        );

        $pin->load('pinnedBy');

        return $this->successResponse(
            'Node data pinned successfully.',
            new PinnedNodeDataResource($pin),
            201,
        );
    }

    /**
     * Toggle pinned data active state.
     */
    public function toggle(Workspace $workspace, Workflow $workflow, PinnedNodeData $pinnedNodeData): JsonResponse
    {
        $this->can(Permission::WorkflowUpdate);

        $pin = $this->pinnedDataService->toggleActive($pinnedNodeData);
        $pin->load('pinnedBy');

        return $this->successResponse(
            'Pinned data toggled successfully.',
            new PinnedNodeDataResource($pin),
        );
    }

    /**
     * Unpin data from a node.
     */
    public function destroy(Workspace $workspace, Workflow $workflow, PinnedNodeData $pinnedNodeData): JsonResponse
    {
        $this->can(Permission::WorkflowUpdate);

        $this->pinnedDataService->unpin($pinnedNodeData);

        return $this->successResponse('Pinned data removed successfully.');
    }
}
