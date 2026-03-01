<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowContractTestRun extends Model
{
    protected $fillable = [
        'workspace_id',
        'workflow_id',
        'workflow_contract_snapshot_id',
        'status',
        'results',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'results' => 'array',
            'executed_at' => 'datetime',
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
     * @return BelongsTo<WorkflowContractSnapshot, $this>
     */
    public function contractSnapshot(): BelongsTo
    {
        return $this->belongsTo(WorkflowContractSnapshot::class, 'workflow_contract_snapshot_id');
    }
}
