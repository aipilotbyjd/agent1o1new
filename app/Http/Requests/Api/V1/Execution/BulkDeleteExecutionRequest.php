<?php

namespace App\Http\Requests\Api\V1\Execution;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteExecutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::ExecutionDelete->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'At least one execution ID is required.',
            'ids.array' => 'Execution IDs must be provided as an array.',
            'ids.min' => 'At least one execution ID is required.',
            'ids.max' => 'A maximum of 100 execution IDs can be deleted at once.',
            'ids.*.integer' => 'Each execution ID must be an integer.',
        ];
    }
}
