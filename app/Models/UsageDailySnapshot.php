<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageDailySnapshot extends Model
{
    /** @use HasFactory<\Database\Factories\UsageDailySnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'snapshot_date',
        'credits_used',
        'executions_total',
        'executions_succeeded',
        'executions_failed',
        'nodes_executed',
        'ai_nodes_executed',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'credits_used' => 'integer',
            'executions_total' => 'integer',
            'executions_succeeded' => 'integer',
            'executions_failed' => 'integer',
            'nodes_executed' => 'integer',
            'ai_nodes_executed' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
