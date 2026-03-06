<?php

namespace App\Http\Requests\Api\V1\Variable;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVariableRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::VariableCreate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $workspaceId = $this->route('workspace')?->id;

        return [
            'key' => [
                'required',
                'string',
                'max:100',
                Rule::unique('variables')->where(fn ($q) => $q->where('workspace_id', $workspaceId)),
            ],
            'value' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'is_secret' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.required' => 'A variable key is required.',
            'key.unique' => 'A variable with this key already exists in the workspace.',
            'value.required' => 'A variable value is required.',
        ];
    }
}
