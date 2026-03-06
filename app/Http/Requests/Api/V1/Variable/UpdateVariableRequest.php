<?php

namespace App\Http\Requests\Api\V1\Variable;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVariableRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::VariableUpdate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $workspaceId = $this->route('workspace')?->id;
        $variableId = $this->route('variable')?->id;

        return [
            'key' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('variables')->where(fn ($q) => $q->where('workspace_id', $workspaceId))->ignore($variableId),
            ],
            'value' => ['sometimes', 'string'],
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
            'key.unique' => 'A variable with this key already exists in the workspace.',
        ];
    }
}
