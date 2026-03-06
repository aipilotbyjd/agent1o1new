<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Variable\StoreVariableRequest;
use App\Http\Requests\Api\V1\Variable\UpdateVariableRequest;
use App\Http\Resources\Api\V1\VariableResource;
use App\Models\Variable;
use App\Models\Workspace;
use App\Services\VariableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VariableController extends Controller
{
    private const SORTABLE_COLUMNS = ['key', 'created_at'];

    private const MAX_PER_PAGE = 100;

    public function __construct(private VariableService $variableService) {}

    /**
     * List variables in a workspace.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::VariableView);

        $query = $workspace->variables()->with('createdBy');

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\%', '\_'], $request->input('search'));
            $query->where('key', 'like', "%{$search}%");
        }

        if ($request->has('is_secret')) {
            $query->where('is_secret', filter_var($request->input('is_secret'), FILTER_VALIDATE_BOOLEAN));
        }

        $sortBy = in_array($request->input('sort_by'), self::SORTABLE_COLUMNS)
            ? $request->input('sort_by')
            : 'created_at';

        $sortDirection = $request->input('sort_direction') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDirection);

        $perPage = min((int) $request->input('per_page', 15), self::MAX_PER_PAGE);
        $variables = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Variables retrieved successfully.',
            VariableResource::collection($variables),
        );
    }

    /**
     * Create a new variable.
     */
    public function store(StoreVariableRequest $request, Workspace $workspace): JsonResponse
    {
        $variable = $this->variableService->create(
            $workspace,
            $request->user(),
            $request->validated(),
        );

        $variable->load('createdBy');

        return $this->successResponse(
            'Variable created successfully.',
            new VariableResource($variable),
            201,
        );
    }

    /**
     * Show a variable.
     */
    public function show(Workspace $workspace, Variable $variable): JsonResponse
    {
        $this->can(Permission::VariableView);

        $variable->load('createdBy');

        return $this->successResponse(
            'Variable retrieved successfully.',
            new VariableResource($variable),
        );
    }

    /**
     * Update a variable.
     */
    public function update(UpdateVariableRequest $request, Workspace $workspace, Variable $variable): JsonResponse
    {
        $variable = $this->variableService->update($variable, $request->validated());
        $variable->load('createdBy');

        return $this->successResponse(
            'Variable updated successfully.',
            new VariableResource($variable),
        );
    }

    /**
     * Delete a variable.
     */
    public function destroy(Workspace $workspace, Variable $variable): JsonResponse
    {
        $this->can(Permission::VariableDelete);

        $this->variableService->delete($variable);

        return $this->successResponse('Variable deleted successfully.');
    }
}
