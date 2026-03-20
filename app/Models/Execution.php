<?php

namespace App\Models;

use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'node_count',
        'completed_node_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExecutionStatus::class,
            'mode' => ExecutionMode::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'estimated_cost_usd' => 'decimal:4',
            'trigger_data' => 'array',
            'result_data' => 'array',
            'error' => 'array',
            'is_deterministic_replay' => 'boolean',
        ];
    }

    // ── Relationships ─────────────────────────────────────────

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
        return $this->hasMany(ExecutionNode::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<ExecutionLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ExecutionLog::class)->orderBy('logged_at');
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

    /**
     * @return HasOne<ExecutionReplayPack, $this>
     */
    public function replayPack(): HasOne
    {
        return $this->hasOne(ExecutionReplayPack::class);
    }

    /**
     * @return HasMany<ConnectorCallAttempt, $this>
     */
    public function connectorAttempts(): HasMany
    {
        return $this->hasMany(ConnectorCallAttempt::class);
    }

    /**
     * @return HasOne<ExecutionCheckpoint, $this>
     */
    public function checkpoint(): HasOne
    {
        return $this->hasOne(ExecutionCheckpoint::class);
    }

    // ── State Transitions ─────────────────────────────────────

    public function start(): void
    {
        $this->update([
            'status' => ExecutionStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function complete(?array $resultData = null, ?int $durationMs = null): void
    {
        $this->update([
            'status' => ExecutionStatus::Completed,
            'finished_at' => now(),
            'duration_ms' => $durationMs,
            'result_data' => $resultData,
        ]);
    }

    public function fail(?array $error = null, ?int $durationMs = null): void
    {
        $this->update([
            'status' => ExecutionStatus::Failed,
            'finished_at' => now(),
            'duration_ms' => $durationMs,
            'error' => $error,
        ]);
    }

    public function cancel(): void
    {
        $this->update([
            'status' => ExecutionStatus::Cancelled,
            'finished_at' => now(),
            'duration_ms' => $this->started_at
                ? (int) $this->started_at->diffInMilliseconds(now())
                : null,
        ]);
    }

    public function markWaiting(\Carbon\CarbonInterface $resumeAt, ?array $meta = null): void
    {
        $this->update([
            'status' => ExecutionStatus::Waiting,
            'result_data' => $meta,
        ]);
    }

    public function resume(): void
    {
        $this->update([
            'status' => ExecutionStatus::Running,
        ]);
    }

    public function canRetry(): bool
    {
        return $this->status === ExecutionStatus::Failed
            && $this->attempt < $this->max_attempts;
    }

    public function canCancel(): bool
    {
        return $this->status->isActive();
    }

    // ── Scopes ────────────────────────────────────────────────

    /**
     * @param  Builder<self>  $query
     */
    public function scopeByStatus(Builder $query, ExecutionStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [
            ExecutionStatus::Pending,
            ExecutionStatus::Running,
            ExecutionStatus::Waiting,
        ]);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeTerminal(Builder $query): void
    {
        $query->whereIn('status', [
            ExecutionStatus::Completed,
            ExecutionStatus::Failed,
            ExecutionStatus::Cancelled,
        ]);
    }
}
