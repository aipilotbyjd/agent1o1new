<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Execution
 */
class ExecutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'workspace_id' => $this->workspace_id,
            'status' => $this->status->value,
            'mode' => $this->mode->value,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'duration_ms' => $this->duration_ms,
            'error' => $this->error,
            'attempt' => $this->attempt,
            'max_attempts' => $this->max_attempts,
            'credits_consumed' => $this->credits_consumed,
            'parent_execution_id' => $this->parent_execution_id,
            'is_deterministic_replay' => $this->is_deterministic_replay,
            'workflow' => new WorkflowResource($this->whenLoaded('workflow')),
            'triggered_by' => new UserResource($this->whenLoaded('triggeredBy')),
            'nodes' => ExecutionNodeResource::collection($this->whenLoaded('nodes')),
            'nodes_count' => $this->whenCounted('nodes'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
