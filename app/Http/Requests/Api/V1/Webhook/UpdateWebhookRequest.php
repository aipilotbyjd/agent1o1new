<?php

namespace App\Http\Requests\Api\V1\Webhook;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::WebhookUpdate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'path' => ['sometimes', 'nullable', 'string', 'max:100'],
            'methods' => ['sometimes', 'array', 'min:1'],
            'methods.*' => ['string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
            'is_active' => ['sometimes', 'boolean'],
            'auth_type' => ['sometimes', 'string', Rule::in(['none', 'header', 'basic', 'bearer'])],
            'auth_config' => ['sometimes', 'nullable', 'array'],
            'rate_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'response_mode' => ['sometimes', 'string', Rule::in(['immediate', 'wait'])],
            'response_status' => ['sometimes', 'integer', 'min:100', 'max:599'],
            'response_body' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
