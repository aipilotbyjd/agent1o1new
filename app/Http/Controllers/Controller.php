<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Traits\ApiResponse;

abstract class Controller
{
    use ApiResponse;

    /**
     * Abort 403 if the user does not have the given permission.
     */
    protected function can(Permission $permission): void
    {
        $permissions = auth()->user()->workspacePermissions ?? [];

        if (! in_array($permission->value, $permissions, true)) {
            abort(403, 'Unauthorized.');
        }
    }

    /**
     * Check if the user has ANY of the given permissions.
     */
    protected function canAny(Permission ...$permissions): bool
    {
        $userPermissions = auth()->user()->workspacePermissions ?? [];

        foreach ($permissions as $permission) {
            if (in_array($permission->value, $userPermissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Abort 403 if the user is not the given role.
     */
    protected function requireRole(Role $role): void
    {
        $currentRole = request()->attributes->get('workspace_role');

        if ($currentRole !== $role) {
            abort(403, "Only the workspace {$role->value} can do this.");
        }
    }
}
