<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\WorkflowTemplate
 */
class WorkflowTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'icon' => $this->icon,
            'color' => $this->color,
            'tags' => $this->tags,
            'trigger_type' => $this->trigger_type,
            'thumbnail_url' => $this->thumbnail_url,
            'instructions' => $this->instructions,
            'required_credentials' => $this->required_credentials,
            'is_featured' => $this->is_featured,
            'usage_count' => $this->usage_count,
            'nodes' => $this->when($request->routeIs('*.show'), $this->nodes),
            'edges' => $this->when($request->routeIs('*.show'), $this->edges),
            'viewport' => $this->when($request->routeIs('*.show'), $this->viewport),
            'settings' => $this->when($request->routeIs('*.show'), $this->settings),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
