<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExecutionNode extends Model
{
    /** @use HasFactory<\Database\Factories\ExecutionNodeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'execution_id',
        'node_id',
        'node_type',
        'node_name',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'input_data',
        'output_data',
        'error',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'input_data' => 'array',
            'output_data' => 'array',
            'error' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /**
     * @return HasMany<ExecutionLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ExecutionLog::class);
    }
}
