<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CredentialResource;
use App\Models\Workspace;
use App\Services\OAuthCredentialFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OAuthCredentialController extends Controller
{
    public function __construct(private OAuthCredentialFlowService $oauthService) {}

    /**
     * Initiate OAuth2 authorization flow for a credential type.
     */
    public function initiate(Request $request, Workspace $workspace): JsonResponse
    {
        $this->can(Permission::CredentialCreate);

        $validated = $request->validate([
            'credential_type' => ['required', 'string', 'max:100'],
            'credential_id' => ['nullable', 'integer', 'exists:credentials,id'],
        ]);

        $result = $this->oauthService->initiate(
            $workspace,
            $request->user(),
            $validated['credential_type'],
            $validated['credential_id'] ?? null,
        );

        return $this->successResponse(
            'OAuth authorization URL generated.',
            $result,
        );
    }

    /**
     * Handle OAuth2 callback.
     */
    public function callback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', 'string', 'uuid'],
            'code' => ['required', 'string'],
        ]);

        $credential = $this->oauthService->handleCallback(
            $validated['state'],
            $validated['code'],
        );

        $credential->load('creator');

        return $this->successResponse(
            'OAuth credential created successfully.',
            new CredentialResource($credential),
            201,
        );
    }
}
