<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Workspace\StoreInvitationRequest;
use App\Http\Resources\Api\V1\InvitationResource;
use App\Http\Resources\Api\V1\WorkspaceResource;
use App\Models\Invitation;
use App\Models\Workspace;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function __construct(private InvitationService $invitationService) {}

    /**
     * List pending invitations for a workspace.
     */
    public function index(Workspace $workspace): JsonResponse
    {
        $this->can(Permission::MemberView);

        $invitations = $workspace->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with('inviter')
            ->get();

        return $this->successResponse(
            'Invitations retrieved successfully.',
            InvitationResource::collection($invitations),
        );
    }

    /**
     * Send a new invitation.
     */
    public function store(StoreInvitationRequest $request, Workspace $workspace): JsonResponse
    {
        $invitation = $this->invitationService->send(
            $workspace,
            $request->user(),
            $request->validated(),
        );

        return $this->successResponse(
            'Invitation sent successfully.',
            new InvitationResource($invitation->load('inviter')),
            201,
        );
    }

    /**
     * Cancel a pending invitation.
     */
    public function destroy(Workspace $workspace, Invitation $invitation): JsonResponse
    {
        $this->can(Permission::MemberInvite);

        $invitation->delete();

        return $this->successResponse('Invitation cancelled successfully.');
    }

    /**
     * Accept an invitation by token.
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = $this->invitationService->accept($token, $request->user());

        return $this->successResponse(
            'Invitation accepted successfully.',
            new WorkspaceResource($invitation->workspace),
        );
    }

    /**
     * Decline an invitation by token.
     */
    public function decline(Request $request, string $token): JsonResponse
    {
        $this->invitationService->decline($token, $request->user());

        return $this->successResponse('Invitation declined successfully.');
    }
}
