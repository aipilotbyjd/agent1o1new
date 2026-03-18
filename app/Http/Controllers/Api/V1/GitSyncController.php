<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\GitSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Import workflows from a Git sync payload.
     */
    public function import(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::GitSyncImport);

        $request->validate([
            'workflows' => ['required', 'array', 'min:1'],
            'workflows.*' => ['required', 'array'],
            'workflows.*.format_version' => ['required', 'string'],
            'workflows.*.workflow' => ['required', 'array'],
        ]);

        $result = $this->gitSyncService->importAll(
            $request->only('workflows'),
            $workspace,
            $request->user(),
        );

        $statusCode = empty($result['errors']) ? 200 : 207;

        return $this->successResponse(
            "Git sync import completed: {$result['imported']} imported, {$result['skipped']} skipped.",
            $result,
            $statusCode,
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
