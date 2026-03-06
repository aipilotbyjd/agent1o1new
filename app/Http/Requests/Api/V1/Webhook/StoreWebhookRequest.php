<?php

namespace App\Http\Requests\Api\V1\Webhook;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::WebhookCreate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'path' => ['nullable', 'string', 'max:100'],
            'methods' => ['nullable', 'array', 'min:1'],
            'methods.*' => ['string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
            'auth_type' => ['nullable', 'string', Rule::in(['none', 'header', 'basic', 'bearer'])],
            'auth_config' => ['nullable', 'array'],
            'rate_limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'response_mode' => ['nullable', 'string', Rule::in(['immediate', 'wait'])],
            'response_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'response_body' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'methods.*.in' => 'Each method must be one of: GET, POST, PUT, PATCH, DELETE.',
            'auth_type.in' => 'Auth type must be one of: none, header, basic, bearer.',
            'response_mode.in' => 'Response mode must be either immediate or wait.',
        ];
    }
}
