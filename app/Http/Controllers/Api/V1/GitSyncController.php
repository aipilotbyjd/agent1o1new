<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\GitSyncService;
use Illuminate\Http\JsonResponse;

class GitSyncController extends Controller
{
    public function __construct(private GitSyncService $gitSyncService) {}

    /**
     * Get Git sync status.
     */
    public function status(Workspace $workspace): JsonResponse
    {
        $this->can(Permission::WorkspaceView);

        $status = $this->gitSyncService->status($workspace);

        return $this->successResponse(
            'Git sync status retrieved successfully.',
            $status,
        );
    }

    /**
     * Export all workflows for Git sync.
     */
    public function export(Workspace $workspace): JsonResponse
    {
        $this->can(Permission::WorkflowExport);

        $data = $this->gitSyncService->exportAll($workspace);

        return $this->successResponse(
            'Workflows exported for Git sync successfully.',
            $data,
        );
    }
}
