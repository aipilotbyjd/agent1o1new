<?php

namespace App\Http\Requests\Api\V1\StickyNote;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStickyNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::WorkflowUpdate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:5000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'position_x' => ['nullable', 'numeric'],
            'position_y' => ['nullable', 'numeric'],
            'width' => ['nullable', 'numeric', 'min:50'],
            'height' => ['nullable', 'numeric', 'min:50'],
            'z_index' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.max' => 'The sticky note content must not exceed 5000 characters.',
            'color.regex' => 'The color must be a valid hex color code (e.g. #FF5733).',
            'width.min' => 'The width must be at least 50.',
            'height.min' => 'The height must be at least 50.',
        ];
    }
}
