<?php

namespace App\Http\Resources\Api\V1;

use App\Services\CredentialMaskingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Credential
 */
class CredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $maskingService = app(CredentialMaskingService::class);
        $data = is_string($this->data) ? json_decode($this->data, true) : ($this->data ?? []);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'data' => $maskingService->maskData($data ?: [], $this->type),
            'last_used_at' => $this->last_used_at,
            'expires_at' => $this->expires_at,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
