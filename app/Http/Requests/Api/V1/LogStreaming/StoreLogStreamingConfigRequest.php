<?php

namespace App\Http\Requests\Api\V1\LogStreaming;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class StoreLogStreamingConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::WorkspaceUpdate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'destination_type' => ['required', 'string', 'in:webhook,s3,datadog,elasticsearch,syslog'],
            'destination_config' => ['required', 'array'],
            'event_types' => ['nullable', 'array'],
            'event_types.*' => ['string', 'in:execution.completed,execution.failed,execution.started,execution.cancelled,node.completed,node.failed'],
            'is_active' => ['nullable', 'boolean'],
            'include_node_data' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A configuration name is required.',
            'destination_type.required' => 'A destination type is required.',
            'destination_type.in' => 'The destination type must be one of: webhook, s3, datadog, elasticsearch, syslog.',
            'destination_config.required' => 'The destination configuration is required.',
            'event_types.*.in' => 'Invalid event type specified.',
        ];
    }
}
