<?php

namespace App\Http\Requests\Api\V1\Credential;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::CredentialCreate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $workspaceId = $this->route('workspace')?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('credentials')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->whereNull('deleted_at')),
            ],
            'type' => ['required', 'string', 'max:100'],
            'data' => ['required', 'array'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A credential name is required.',
            'name.unique' => 'A credential with this name already exists in the workspace.',
            'type.required' => 'A credential type is required.',
            'data.required' => 'Credential data is required.',
            'expires_at.after' => 'The expiration date must be in the future.',
        ];
    }
}
