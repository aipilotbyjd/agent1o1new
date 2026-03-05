<?php

namespace App\Http\Requests\Api\V1\Execution;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class TriggerExecutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::WorkflowExecute->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'trigger_data' => ['nullable', 'array'],
        ];
    }
}
