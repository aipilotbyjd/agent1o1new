<?php

namespace App\Http\Requests\Api\V1\Execution;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class CompareExecutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::ExecutionView->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'execution_a' => ['required', 'integer'],
            'execution_b' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'execution_a.required' => 'The first execution ID is required.',
            'execution_a.integer' => 'The first execution ID must be an integer.',
            'execution_b.required' => 'The second execution ID is required.',
            'execution_b.integer' => 'The second execution ID must be an integer.',
        ];
    }
}
