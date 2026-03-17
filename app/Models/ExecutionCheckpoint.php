<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionCheckpoint extends Model
{
    protected $fillable = [
        'execution_id',
        'frontier_state',
        'output_refs',
        'frame_stack',
        'next_sequence',
        'suspend_reason',
        'resume_at',
        'checkpoint_version',
    ];

    protected function casts(): array
    {
        return [
            'frontier_state' => 'array',
            'output_refs' => 'array',
            'frame_stack' => 'array',
            'next_sequence' => 'integer',
            'resume_at' => 'datetime',
            'checkpoint_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }
}
