<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Workflow\StoreWorkflowShareRequest;
use App\Http\Requests\Api\V1\Workflow\UpdateWorkflowShareRequest;
use App\Http\Resources\Api\V1\WorkflowResource;
use App\Http\Resources\Api\V1\WorkflowShareResource;
use App\Models\Workflow;
use App\Models\WorkflowShare;
use App\Models\Workspace;
use App\Services\WorkflowShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowShareController extends Controller
{
    public function __construct(private WorkflowShareService $shareService) {}

    /**
     * List share links for a workflow.
     */
    public function index(Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->can(Permission::WorkflowShare);

        $shares = $workflow->shares()->with('sharedBy')->get();

        return $this->successResponse(
            'Workflow shares retrieved successfully.',
            WorkflowShareResource::collection($shares),
        );
    }

    /**
     * Create a share link for a workflow.
     */
    public function store(StoreWorkflowShareRequest $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $validated = $request->validated();

        $share = $this->shareService->create(
            $workspace,
            $workflow,
            $request->user(),
            $validated,
        );

        $share->load('sharedBy');

        return $this->successResponse(
            'Workflow share link created successfully.',
            new WorkflowShareResource($share),
            201,
        );
    }

    /**
     * Update a share link.
     */
    public function update(UpdateWorkflowShareRequest $request, Workspace $workspace, Workflow $workflow, WorkflowShare $share): JsonResponse
    {
        $validated = $request->validated();

        $share = $this->shareService->update($share, $validated);
        $share->load('sharedBy');

        return $this->successResponse(
            'Workflow share updated successfully.',
            new WorkflowShareResource($share),
        );
    }

    /**
     * Delete a share link.
     */
    public function destroy(Workspace $workspace, Workflow $workflow, WorkflowShare $share): JsonResponse
    {
        $this->can(Permission::WorkflowShare);

        $this->shareService->delete($share);

        return $this->successResponse('Workflow share link deleted successfully.');
    }

    /**
     * View a shared workflow (public — no auth required).
     */
    public function viewPublic(Request $request, string $token): JsonResponse
    {
        $data = $this->shareService->viewByToken(
            $token,
            $request->input('password'),
        );

        return $this->successResponse(
            'Shared workflow retrieved successfully.',
            [
                'workflow' => new WorkflowResource($data['workflow']),
                'allow_clone' => $data['allow_clone'],
            ],
        );
    }

    /**
     * Clone a shared workflow into a workspace (authenticated).
     */
    public function clonePublic(Request $request, Workspace $workspace, string $token): JsonResponse
    {
        $this->can(Permission::WorkflowCreate);

        $workflow = $this->shareService->cloneFromShare(
            $token,
            $workspace,
            $request->user(),
            $request->input('password'),
        );

        return $this->successResponse(
            'Workflow cloned successfully.',
            new WorkflowResource($workflow),
            201,
        );
    }
}
