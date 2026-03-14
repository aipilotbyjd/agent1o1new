<?php

namespace App\Http\Requests\Api\V1\PollingTrigger;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePollingTriggerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::PollingTriggerCreate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'endpoint_url' => ['required', 'string', 'url', 'max:2048'],
            'http_method' => ['nullable', 'string', Rule::in(['GET', 'POST'])],
            'headers' => ['nullable', 'array'],
            'query_params' => ['nullable', 'array'],
            'body' => ['nullable', 'array'],
            'dedup_key' => ['required', 'string', 'max:255'],
            'interval_seconds' => ['nullable', 'integer', 'min:60', 'max:86400'],
            'auth_config' => ['nullable', 'array'],
            'auth_config.type' => ['required_with:auth_config', 'string', Rule::in(['bearer', 'basic'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'endpoint_url.required' => 'An API endpoint URL is required.',
            'dedup_key.required' => 'A deduplication key is required to identify unique records.',
            'interval_seconds.min' => 'Polling interval must be at least 60 seconds.',
            'interval_seconds.max' => 'Polling interval cannot exceed 86400 seconds (24 hours).',
        ];
    }
}
