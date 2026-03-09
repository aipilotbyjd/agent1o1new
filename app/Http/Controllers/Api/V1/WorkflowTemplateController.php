<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WorkflowResource;
use App\Http\Resources\Api\V1\WorkflowTemplateResource;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;
use App\Services\WorkflowTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowTemplateController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private WorkflowTemplateService $templateService) {}

    /**
     * List available workflow templates.
     */
    public function index(Request $request): JsonResponse
    {
        $query = WorkflowTemplate::query()->where('is_active', true);

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\%', '\_'], $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        $query->orderBy('sort_order')->orderByDesc('usage_count');

        $perPage = min((int) $request->input('per_page', 15), self::MAX_PER_PAGE);
        $templates = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Workflow templates retrieved successfully.',
            WorkflowTemplateResource::collection($templates),
        );
    }

    /**
     * Show a single template.
     */
    public function show(WorkflowTemplate $workflowTemplate): JsonResponse
    {
        if (! $workflowTemplate->is_active) {
            return $this->errorResponse('Template not found.', 404);
        }

        return $this->successResponse(
            'Workflow template retrieved successfully.',
            new WorkflowTemplateResource($workflowTemplate),
        );
    }

    /**
     * Create a workflow from a template within a workspace.
     */
    public function use(Request $request, Workspace $workspace, WorkflowTemplate $workflowTemplate): JsonResponse
    {
        $this->can(Permission::WorkflowCreate);

        $workflow = $this->templateService->useTemplate(
            $workflowTemplate,
            $workspace,
            $request->user(),
        );

        return $this->successResponse(
            'Workflow created from template successfully.',
            new WorkflowResource($workflow),
            201,
        );
    }
}
