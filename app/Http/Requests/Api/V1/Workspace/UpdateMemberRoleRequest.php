<?php

namespace App\Http\Requests\Api\V1\Workspace;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        if (! in_array(Permission::MemberUpdate->value, $permissions, true)) {
            return false;
        }

        if ($this->route('workspace')->owner_id === $this->route('user')->id) {
            throw new AuthorizationException('Cannot change the role of the workspace owner.');
        }

        if ($this->input('role') === Role::Admin->value && $this->route('workspace')->owner_id !== $this->user()->id) {
            throw new AuthorizationException('Only the workspace owner can assign the admin role.');
        }

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(array_map(fn (Role $r) => $r->value, Role::assignable()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.required' => 'A role must be specified.',
            'role.in' => 'The selected role is invalid.',
        ];
    }
}
