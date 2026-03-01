<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFixSuggestion extends Model
{
    protected $fillable = [
        'workspace_id',
        'execution_id',
        'workflow_id',
        'failed_node_key',
        'error_message',
        'diagnosis',
        'suggestions',
        'applied_index',
        'model_used',
        'tokens_used',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'suggestions' => 'array',
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
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
