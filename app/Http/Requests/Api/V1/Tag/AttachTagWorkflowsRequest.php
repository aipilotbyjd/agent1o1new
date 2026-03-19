<?php

namespace App\Http\Requests\Api\V1\Tag;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class AttachTagWorkflowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::TagUpdate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'workflow_ids' => ['required', 'array'],
            'workflow_ids.*' => ['integer', 'exists:workflows,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'workflow_ids.required' => 'At least one workflow ID is required.',
            'workflow_ids.array' => 'Workflow IDs must be provided as an array.',
            'workflow_ids.*.integer' => 'Each workflow ID must be an integer.',
            'workflow_ids.*.exists' => 'The selected workflow does not exist.',
        ];
    }
}
