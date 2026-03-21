<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Workflow
 */
class WorkflowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'is_active' => $this->is_active,
            'is_locked' => $this->is_locked,
            'current_version_id' => $this->current_version_id,
            'current_version' => new WorkflowVersionResource($this->whenLoaded('currentVersion')),
            'execution_count' => $this->execution_count,
            'last_executed_at' => $this->last_executed_at,
            'success_rate' => (float) $this->success_rate,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
