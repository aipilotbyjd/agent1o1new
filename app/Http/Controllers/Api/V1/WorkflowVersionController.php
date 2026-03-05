<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Workflow\StoreWorkflowVersionRequest;
use App\Http\Resources\Api\V1\WorkflowVersionResource;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use App\Services\WorkflowVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowVersionController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private WorkflowVersionService $versionService) {}

    /**
     * List all versions for a workflow.
     */
    public function index(Request $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->can(Permission::VersionView);

        $perPage = min((int) $request->input('per_page', 15), self::MAX_PER_PAGE);

        $versions = $workflow->versions()
            ->with('creator')
            ->orderByDesc('version_number')
            ->paginate($perPage);

        return $this->paginatedResponse(
            'Workflow versions retrieved successfully.',
            WorkflowVersionResource::collection($versions),
        );
    }

    /**
     * Create a new workflow version.
     */
    public function store(StoreWorkflowVersionRequest $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $version = $this->versionService->create(
            $workflow,
            $request->user(),
            $request->validated(),
        );

        $version->load('creator');

        return $this->successResponse(
            'Workflow version created successfully.',
            new WorkflowVersionResource($version),
            201,
        );
    }

    /**
     * Show a specific workflow version.
     */
    public function show(Workspace $workspace, Workflow $workflow, WorkflowVersion $version): JsonResponse
    {
        $this->can(Permission::VersionView);

        $version->load('creator');

        return $this->successResponse(
            'Workflow version retrieved successfully.',
            new WorkflowVersionResource($version),
        );
    }

    /**
     * Publish a version, making it the current active version.
     */
    public function publish(Workspace $workspace, Workflow $workflow, WorkflowVersion $version): JsonResponse
    {
        $this->can(Permission::WorkflowUpdate);

        $version = $this->versionService->publish($version);
        $version->load('creator');

        return $this->successResponse(
            'Version published successfully.',
            new WorkflowVersionResource($version),
        );
    }

    /**
     * Rollback to a previous version by creating a new version from it.
     */
    public function rollback(Workspace $workspace, Workflow $workflow, WorkflowVersion $version): JsonResponse
    {
        $this->can(Permission::VersionRestore);

        $newVersion = $this->versionService->rollback($workflow, $version);
        $newVersion->load('creator');

        return $this->successResponse(
            'Rolled back successfully. A new version has been created and published.',
            new WorkflowVersionResource($newVersion),
            201,
        );
    }

    /**
     * Diff two versions of a workflow.
     */
    public function diff(Request $request, Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->can(Permission::VersionView);

        $request->validate([
            'from' => ['required', 'integer'],
            'to' => ['required', 'integer'],
        ]);

        $from = $workflow->versions()->findOrFail($request->integer('from'));
        $to = $workflow->versions()->findOrFail($request->integer('to'));

        $diff = $this->versionService->diff($from, $to);

        return $this->successResponse('Version diff computed successfully.', $diff);
    }
}
