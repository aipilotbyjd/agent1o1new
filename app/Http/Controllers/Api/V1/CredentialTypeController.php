<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CredentialTypeResource;
use App\Models\CredentialType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CredentialTypeController extends Controller
{
    private const MAX_PER_PAGE = 100;

    /**
     * List all credential types.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CredentialType::query();

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\%', '\_'], $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%");
            });
        }

        $query->orderBy('name');

        $perPage = min((int) $request->input('per_page', 50), self::MAX_PER_PAGE);
        $types = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Credential types retrieved successfully.',
            CredentialTypeResource::collection($types),
        );
    }

    /**
     * Show a single credential type with fields schema.
     */
    public function show(CredentialType $credentialType): JsonResponse
    {
        return $this->successResponse(
            'Credential type retrieved successfully.',
            new CredentialTypeResource($credentialType),
        );
    }
}
