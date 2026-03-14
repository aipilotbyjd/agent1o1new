<?php

namespace App\Http\Requests\Api\V1\PollingTrigger;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePollingTriggerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::PollingTriggerUpdate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'endpoint_url' => ['sometimes', 'string', 'url', 'max:2048'],
            'http_method' => ['sometimes', 'string', Rule::in(['GET', 'POST'])],
            'headers' => ['sometimes', 'nullable', 'array'],
            'query_params' => ['sometimes', 'nullable', 'array'],
            'body' => ['sometimes', 'nullable', 'array'],
            'dedup_key' => ['sometimes', 'string', 'max:255'],
            'interval_seconds' => ['sometimes', 'integer', 'min:60', 'max:86400'],
            'is_active' => ['sometimes', 'boolean'],
            'auth_config' => ['sometimes', 'nullable', 'array'],
            'auth_config.type' => ['required_with:auth_config', 'string', Rule::in(['bearer', 'basic'])],
        ];
    }
}
