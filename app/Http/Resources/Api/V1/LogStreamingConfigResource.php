<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\LogStreamingConfig
 */
class LogStreamingConfigResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'destination_type' => $this->destination_type,
            'event_types' => $this->event_types,
            'is_active' => $this->is_active,
            'include_node_data' => $this->include_node_data,
            'last_sent_at' => $this->last_sent_at,
            'error_count' => $this->error_count,
            'last_error' => $this->last_error,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
