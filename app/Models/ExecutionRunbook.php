<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionRunbook extends Model
{
    protected $fillable = [
        'workspace_id',
        'workflow_id',
        'execution_id',
        'severity',
        'title',
        'summary',
        'steps',
        'tags',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'tags' => 'array',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
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
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
