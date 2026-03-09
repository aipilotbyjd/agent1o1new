<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PinnedNodeData
 */
class PinnedNodeDataResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'node_id' => $this->node_id,
            'node_name' => $this->node_name,
            'data' => $this->data,
            'is_active' => $this->is_active,
            'pinned_by' => new UserResource($this->whenLoaded('pinnedBy')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
