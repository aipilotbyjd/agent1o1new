<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Execution extends Model
{
    /** @use HasFactory<\Database\Factories\ExecutionFactory> */
    use HasFactory;

    protected $fillable = [
        'workflow_id',
        'workspace_id',
        'status',
        'mode',
        'triggered_by',
        'started_at',
        'finished_at',
        'duration_ms',
        'estimated_cost_usd',
        'credits_consumed',
        'trigger_data',
        'result_data',
        'error',
        'attempt',
        'max_attempts',
        'parent_execution_id',
        'replay_of_execution_id',
        'is_deterministic_replay',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'estimated_cost_usd' => 'decimal:4',
            'trigger_data' => 'array',
            'result_data' => 'array',
            'error' => 'array',
            'is_deterministic_replay' => 'boolean',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * @return HasMany<ExecutionNode, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(ExecutionNode::class);
    }

    /**
     * @return HasMany<ExecutionLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ExecutionLog::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parentExecution(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_execution_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function replayOfExecution(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replay_of_execution_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function childExecutions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_execution_id');
    }
}
