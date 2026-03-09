<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WorkflowResource;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\WorkflowImportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowImportExportController extends Controller
{
    public function __construct(private WorkflowImportExportService $importExportService) {}

    /**
     * Export a workflow as JSON.
     */
    public function export(Workspace $workspace, Workflow $workflow): JsonResponse
    {
        $this->can(Permission::WorkflowExport);

        $data = $this->importExportService->export($workflow);

        return $this->successResponse(
            'Workflow exported successfully.',
            $data,
        );
    }

    /**
     * Import a workflow from JSON.
     */
    public function import(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::WorkflowImport);

        $request->validate([
            'workflow_data' => ['required', 'array'],
            'workflow_data.format_version' => ['required', 'string'],
            'workflow_data.workflow' => ['required', 'array'],
        ]);

        $workflow = $this->importExportService->import(
            $request->input('workflow_data'),
            $workspace,
            $request->user(),
        );

        return $this->successResponse(
            'Workflow imported successfully.',
            new WorkflowResource($workflow),
            201,
        );
    }
}
