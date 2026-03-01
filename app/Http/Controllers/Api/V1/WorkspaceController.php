<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Api\V1\Workspace\UpdateWorkspaceRequest;
use App\Http\Resources\Api\V1\WorkspaceResource;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    private const SORTABLE_COLUMNS = ['name', 'created_at', 'updated_at'];

    private const MAX_PER_PAGE = 100;

    public function __construct(private WorkspaceService $workspaceService) {}

    /**
     * List all workspaces accessible to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $query = Workspace::query()
            ->where(function ($q) use ($userId) {
                $q->where('owner_id', $userId)
                    ->orWhereHas('members', fn ($m) => $m->where('user_id', $userId));
            })
            ->with('owner');

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\%', '\_'], $request->input('search'));
            $query->where('name', 'like', "%{$search}%");
        }

        $sortBy = in_array($request->input('sort_by'), self::SORTABLE_COLUMNS)
            ? $request->input('sort_by')
            : 'created_at';

        $sortDirection = $request->input('sort_direction') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDirection);

        $perPage = min((int) $request->input('per_page', 15), self::MAX_PER_PAGE);
        $workspaces = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Workspaces retrieved successfully.',
            WorkspaceResource::collection($workspaces),
        );
    }

    /**
     * Create a new workspace.
     */
    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        $workspace = $this->workspaceService->create(
            $request->user(),
            $request->validated(),
        );

        $workspace->load('owner');

        return $this->successResponse(
            'Workspace created successfully.',
            new WorkspaceResource($workspace),
            201,
        );
    }

    /**
     * Show a workspace.
     */
    public function show(Workspace $workspace): JsonResponse
    {
        $this->can(Permission::WorkspaceView);

        $workspace->load('owner');

        return $this->successResponse(
            'Workspace details.',
            new WorkspaceResource($workspace),
        );
    }

    /**
     * Update a workspace.
     */
    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): JsonResponse
    {
        $workspace = $this->workspaceService->update($workspace, $request->validated());
        $workspace->load('owner');

        return $this->successResponse(
            'Workspace updated successfully.',
            new WorkspaceResource($workspace),
        );
    }

    /**
     * Delete a workspace.
     */
    public function destroy(Workspace $workspace): JsonResponse
    {
        $this->can(Permission::WorkspaceDelete);

        $this->workspaceService->delete($workspace);

        return $this->successResponse('Workspace deleted successfully.');
    }
}
