<?php

namespace App\Http\Requests\Api\V1\Workflow;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkflowVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::WorkflowUpdate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'trigger_type' => ['nullable', 'string', 'max:50'],
            'trigger_config' => ['nullable', 'array'],
            'nodes' => ['required', 'array', 'min:1'],
            'nodes.*.id' => ['required', 'string'],
            'nodes.*.type' => ['required', 'string'],
            'edges' => ['present', 'array'],
            'edges.*.source' => ['required', 'string'],
            'edges.*.target' => ['required', 'string'],
            'viewport' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'change_summary' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nodes.required' => 'At least one node is required to create a version.',
            'nodes.min' => 'At least one node is required to create a version.',
            'nodes.*.id.required' => 'Each node must have an id.',
            'nodes.*.type.required' => 'Each node must have a type.',
            'edges.present' => 'Edges array is required (can be empty).',
            'edges.*.source.required' => 'Each edge must have a source node id.',
            'edges.*.target.required' => 'Each edge must have a target node id.',
        ];
    }
}
