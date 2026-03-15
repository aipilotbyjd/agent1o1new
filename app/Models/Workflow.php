<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workflow extends Model
{
    /** @use HasFactory<\Database\Factories\WorkflowFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'description',
        'icon',
        'color',
        'is_active',
        'is_locked',
        'current_version_id',
        'execution_count',
        'last_executed_at',
        'success_rate',
        'error_workflow_id',
        'trigger_type',
        'cron_expression',
        'next_run_at',
        'last_cron_run_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'execution_count' => 'integer',
            'last_executed_at' => 'datetime',
            'success_rate' => 'decimal:2',
            'next_run_at' => 'datetime',
            'last_cron_run_at' => 'datetime',
        ];
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'current_version_id');
    }

    /**
     * @return HasMany<WorkflowVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    /**
     * @return BelongsToMany<Credential, $this>
     */
    public function credentials(): BelongsToMany
    {
        return $this->belongsToMany(Credential::class, 'workflow_credentials')
            ->withPivot('node_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'workflow_tags');
    }

    /**
     * @return HasMany<Execution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    /**
     * @return HasMany<Webhook, $this>
     */
    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    /**
     * @return HasMany<PollingTrigger, $this>
     */
    public function pollingTriggers(): HasMany
    {
        return $this->hasMany(PollingTrigger::class);
    }

    /**
     * @return HasMany<StickyNote, $this>
     */
    public function stickyNotes(): HasMany
    {
        return $this->hasMany(StickyNote::class);
    }

    /**
     * @return HasMany<WorkflowShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(WorkflowShare::class);
    }

    /**
     * @return HasMany<PinnedNodeData, $this>
     */
    public function pinnedData(): HasMany
    {
        return $this->hasMany(PinnedNodeData::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function errorWorkflow(): BelongsTo
    {
        return $this->belongsTo(self::class, 'error_workflow_id');
    }

    public function activate(): void
    {
        $this->update(['is_active' => true]);

        app(\App\Services\WebhookAutoRegistrationService::class)
            ->registerForWorkflow($this);
    }

    public function deactivate(): void
    {
        app(\App\Services\WebhookAutoRegistrationService::class)
            ->unregisterForWorkflow($this);

        $this->update(['is_active' => false]);
    }
}
