<?php

namespace App\Http\Requests\Api\V1\Credential;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::CredentialUpdate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $workspaceId = $this->route('workspace')?->id;
        $credentialId = $this->route('credential')?->id;

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('credentials')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->whereNull('deleted_at'))->ignore($credentialId),
            ],
            'data' => ['sometimes', 'array'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A credential with this name already exists in the workspace.',
            'expires_at.after' => 'The expiration date must be in the future.',
        ];
    }
}
