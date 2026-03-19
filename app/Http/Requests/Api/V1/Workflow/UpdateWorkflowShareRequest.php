<?php

namespace App\Http\Requests\Api\V1\Workflow;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkflowShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::WorkflowShare->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'is_public' => ['nullable', 'boolean'],
            'allow_clone' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:6'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.min' => 'The password must be at least 6 characters.',
            'expires_at.after' => 'The expiration date must be in the future.',
        ];
    }
}
