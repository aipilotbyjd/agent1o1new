<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    /** @use HasFactory<\Database\Factories\CreditTransactionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'usage_period_id',
        'type',
        'credits',
        'description',
        'execution_id',
        'execution_node_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'credits' => 'integer',
            'created_at' => 'datetime',
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
     * @return BelongsTo<WorkspaceUsagePeriod, $this>
     */
    public function usagePeriod(): BelongsTo
    {
        return $this->belongsTo(WorkspaceUsagePeriod::class);
    }

    /**
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }
}
