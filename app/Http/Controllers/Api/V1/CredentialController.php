<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Credential\StoreCredentialRequest;
use App\Http\Requests\Api\V1\Credential\UpdateCredentialRequest;
use App\Http\Resources\Api\V1\CredentialResource;
use App\Models\Credential;
use App\Models\Workspace;
use App\Services\CredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CredentialController extends Controller
{
    private const SORTABLE_COLUMNS = ['name', 'type', 'created_at', 'last_used_at'];

    private const MAX_PER_PAGE = 100;

    public function __construct(private CredentialService $credentialService) {}

    /**
     * List credentials in a workspace.
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::CredentialView);

        $query = $workspace->credentials()->with('creator');

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\%', '\_'], $request->input('search'));
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $sortBy = in_array($request->input('sort_by'), self::SORTABLE_COLUMNS)
            ? $request->input('sort_by')
            : 'created_at';

        $sortDirection = $request->input('sort_direction') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDirection);

        $perPage = min((int) $request->input('per_page', 15), self::MAX_PER_PAGE);
        $credentials = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Credentials retrieved successfully.',
            CredentialResource::collection($credentials),
        );
    }

    /**
     * Create a new credential.
     */
    public function store(StoreCredentialRequest $request, Workspace $workspace): JsonResponse
    {
        $credential = $this->credentialService->create(
            $workspace,
            $request->user(),
            $request->validated(),
        );

        $credential->load('creator');

        return $this->successResponse(
            'Credential created successfully.',
            new CredentialResource($credential),
            201,
        );
    }

    /**
     * Show a credential (without sensitive data).
     */
    public function show(Workspace $workspace, Credential $credential): JsonResponse
    {
        $this->can(Permission::CredentialView);

        $credential->load('creator');

        return $this->successResponse(
            'Credential retrieved successfully.',
            new CredentialResource($credential),
        );
    }

    /**
     * Update a credential.
     */
    public function update(UpdateCredentialRequest $request, Workspace $workspace, Credential $credential): JsonResponse
    {
        $credential = $this->credentialService->update($credential, $request->validated());
        $credential->load('creator');

        return $this->successResponse(
            'Credential updated successfully.',
            new CredentialResource($credential),
        );
    }

    /**
     * Delete a credential (soft delete).
     */
    public function destroy(Workspace $workspace, Credential $credential): JsonResponse
    {
        $this->can(Permission::CredentialDelete);

        $this->credentialService->delete($credential);

        return $this->successResponse('Credential deleted successfully.');
    }

    /**
     * Test a credential's validity.
     */
    public function test(Workspace $workspace, Credential $credential): JsonResponse
    {
        $this->can(Permission::CredentialTest);

        $result = $this->credentialService->test($credential);

        $statusCode = $result['success'] ? 200 : 422;

        return $this->successResponse($result['message'], $result, $statusCode);
    }
}
