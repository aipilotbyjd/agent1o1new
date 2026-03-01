<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkflowTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\WorkflowTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'icon',
        'color',
        'tags',
        'trigger_type',
        'trigger_config',
        'nodes',
        'edges',
        'viewport',
        'settings',
        'thumbnail_url',
        'instructions',
        'required_credentials',
        'is_featured',
        'is_active',
        'usage_count',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'trigger_config' => 'array',
            'nodes' => 'array',
            'edges' => 'array',
            'viewport' => 'array',
            'settings' => 'array',
            'required_credentials' => 'array',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
