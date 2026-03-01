<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowContractSnapshot extends Model
{
    protected $fillable = [
        'workflow_id',
        'workflow_version_id',
        'graph_hash',
        'status',
        'contracts',
        'issues',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'contracts' => 'array',
            'issues' => 'array',
            'generated_at' => 'datetime',
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
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function workflowVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class);
    }
}
