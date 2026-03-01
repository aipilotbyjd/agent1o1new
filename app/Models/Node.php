<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Node extends Model
{
    /** @use HasFactory<\Database\Factories\NodeFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'type',
        'name',
        'description',
        'icon',
        'color',
        'node_kind',
        'config_schema',
        'input_schema',
        'output_schema',
        'credential_type',
        'cost_hint_usd',
        'latency_hint_ms',
        'is_active',
        'is_premium',
        'docs_url',
    ];

    protected function casts(): array
    {
        return [
            'config_schema' => 'array',
            'input_schema' => 'array',
            'output_schema' => 'array',
            'cost_hint_usd' => 'decimal:4',
            'latency_hint_ms' => 'integer',
            'is_active' => 'boolean',
            'is_premium' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<NodeCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(NodeCategory::class, 'category_id');
    }
}
