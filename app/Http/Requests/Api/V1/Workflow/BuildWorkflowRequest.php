<?php

namespace App\Http\Requests\Api\V1\Workflow;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class BuildWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::AiGenerate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'description.required' => 'A natural language description of the workflow is required.',
            'description.min' => 'The description must be at least 10 characters so the AI has enough context.',
            'description.max' => 'The description may not exceed 2000 characters.',
        ];
    }
}
