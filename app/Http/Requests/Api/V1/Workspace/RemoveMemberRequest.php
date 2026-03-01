<?php

namespace App\Http\Requests\Api\V1\Workspace;

use App\Enums\Permission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class RemoveMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        if (! in_array(Permission::MemberRemove->value, $permissions, true)) {
            return false;
        }

        if ($this->route('workspace')->owner_id === $this->route('user')->id) {
            throw new AuthorizationException('Cannot remove the workspace owner.');
        }

        if ($this->user()->id === $this->route('user')->id) {
            throw new AuthorizationException('Cannot remove yourself. Use the leave endpoint instead.');
        }

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
