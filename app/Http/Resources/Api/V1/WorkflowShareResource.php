<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\WorkflowShare
 */
class WorkflowShareResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'share_token' => $this->share_token,
            'share_url' => config('app.frontend_url', config('app.url')).'/shared/'.$this->share_token,
            'is_public' => $this->is_public,
            'allow_clone' => $this->allow_clone,
            'has_password' => $this->password !== null,
            'expires_at' => $this->expires_at,
            'is_expired' => $this->isExpired(),
            'view_count' => $this->view_count,
            'clone_count' => $this->clone_count,
            'shared_by' => new UserResource($this->whenLoaded('sharedBy')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
