<?php

namespace App\Http\Requests\Api\V1\Tag;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = $this->user()->workspacePermissions ?? [];

        return in_array(Permission::TagUpdate->value, $permissions, true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $workspaceId = $this->route('workspace')?->id;
        $tagId = $this->route('tag')?->id;

        return [
            'name' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('tags')->where(fn ($q) => $q->where('workspace_id', $workspaceId))->ignore($tagId),
            ],
            'color' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A tag with this name already exists in the workspace.',
        ];
    }
}
