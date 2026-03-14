<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PollingTrigger extends Model
{
    /** @use HasFactory<\Database\Factories\PollingTriggerFactory> */
    use HasFactory;

    protected $fillable = [
        'workflow_id',
        'workspace_id',
        'endpoint_url',
        'http_method',
        'headers',
        'query_params',
        'body',
        'dedup_key',
        'interval_seconds',
        'is_active',
        'auth_config',
        'last_seen_ids',
        'last_polled_at',
        'next_poll_at',
        'poll_count',
        'trigger_count',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'query_params' => 'array',
            'body' => 'array',
            'is_active' => 'boolean',
            'auth_config' => 'array',
            'last_seen_ids' => 'array',
            'last_polled_at' => 'datetime',
            'next_poll_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
