<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Workflow\BuildWorkflowRequest;
use App\Http\Resources\Api\V1\WorkflowResource;
use App\Models\Workspace;
use App\Services\WorkflowBuilderService;
use Illuminate\Http\JsonResponse;

class WorkflowBuilderController extends Controller
{
    public function __construct(private WorkflowBuilderService $builderService) {}

    /**
     * Generate a workflow from a natural language description.
     *
     * The AI agent interprets the description, selects appropriate node types
     * from the node registry, and returns a fully-wired workflow with a
     * published version ready to load into the canvas.
     */
    public function build(BuildWorkflowRequest $request, Workspace $workspace): JsonResponse
    {
        $workflow = $this->builderService->build(
            $workspace,
            $request->user(),
            $request->string('description')->toString(),
        );

        return $this->successResponse(
            'Workflow generated successfully.',
            new WorkflowResource($workflow),
            201,
        );
    }
}
