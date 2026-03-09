<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StickyNote
 */
class StickyNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'content' => $this->content,
            'color' => $this->color,
            'position_x' => (float) $this->position_x,
            'position_y' => (float) $this->position_y,
            'width' => (float) $this->width,
            'height' => (float) $this->height,
            'z_index' => $this->z_index,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
