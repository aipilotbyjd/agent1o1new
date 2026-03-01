<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Workspace\RemoveMemberRequest;
use App\Http\Requests\Api\V1\Workspace\UpdateMemberRoleRequest;
use App\Http\Resources\Api\V1\WorkspaceMemberResource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceMemberController extends Controller
{
    /**
     * List all members of a workspace.
     */
    public function index(Workspace $workspace): JsonResponse
    {
        $this->can(Permission::MemberView);

        $members = $workspace->members()->get();

        return $this->successResponse(
            'Workspace members retrieved successfully.',
            WorkspaceMemberResource::collection($members),
        );
    }

    /**
     * Update a member's role.
     */
    public function update(UpdateMemberRoleRequest $request, Workspace $workspace, User $user): JsonResponse
    {
        $workspace->members()->updateExistingPivot($user->id, [
            'role' => $request->validated('role'),
        ]);

        return $this->successResponse('Member role updated successfully.');
    }

    /**
     * Remove a member from the workspace.
     */
    public function destroy(RemoveMemberRequest $request, Workspace $workspace, User $user): JsonResponse
    {
        $workspace->members()->detach($user->id);

        return $this->successResponse('Member removed successfully.');
    }

    /**
     * Leave a workspace.
     */
    public function leave(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $request->user();

        if ($workspace->owner_id === $user->id) {
            return $this->errorResponse('Workspace owner cannot leave. Transfer ownership first or delete the workspace.', 403);
        }

        $workspace->members()->detach($user->id);

        return $this->successResponse('You have left the workspace.');
    }
}
