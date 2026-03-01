<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveWorkspaceRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');

        if (! $workspace instanceof Workspace) {
            return $next($request);
        }

        $user = $request->user();

        // Owner — resolved from workspace.owner_id, never from pivot
        if ($workspace->owner_id === $user->id) {
            $user->workspacePermissions = array_map(
                fn (Permission $p) => $p->value,
                Permission::cases(),
            );
            $request->attributes->set('workspace_role', Role::Owner);

            return $next($request);
        }

        // Everyone else — one DB query
        $member = $workspace->members()
            ->where('user_id', $user->id)
            ->first();

        if (! $member) {
            return response()->json([
                'success' => false,
                'statusCode' => 403,
                'message' => 'You are not a member of this workspace.',
            ], 403);
        }

        $role = Role::from($member->pivot->role);
        $user->workspacePermissions = array_map(
            fn (Permission $p) => $p->value,
            $role->permissions(),
        );
        $request->attributes->set('workspace_role', $role);

        return $next($request);
    }
}
