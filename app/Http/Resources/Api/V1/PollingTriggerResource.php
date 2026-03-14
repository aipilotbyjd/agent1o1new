<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PollingTrigger
 */
class PollingTriggerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'endpoint_url' => $this->endpoint_url,
            'http_method' => $this->http_method,
            'headers' => $this->headers,
            'query_params' => $this->query_params,
            'dedup_key' => $this->dedup_key,
            'interval_seconds' => $this->interval_seconds,
            'is_active' => $this->is_active,
            'last_polled_at' => $this->last_polled_at,
            'next_poll_at' => $this->next_poll_at,
            'poll_count' => $this->poll_count,
            'trigger_count' => $this->trigger_count,
            'last_error' => $this->last_error,
            'workflow' => new WorkflowResource($this->whenLoaded('workflow')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
