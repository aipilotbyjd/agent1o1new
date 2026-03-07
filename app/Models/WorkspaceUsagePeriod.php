<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceUsagePeriod extends Model
{
    /** @use HasFactory<\Database\Factories\WorkspaceUsagePeriodFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'subscription_id',
        'period_start',
        'period_end',
        'credits_limit',
        'credits_from_packs',
        'credits_rolled_over',
        'credits_used',
        'credits_overage',
        'executions_total',
        'executions_succeeded',
        'executions_failed',
        'nodes_executed',
        'ai_nodes_executed',
        'is_current',
        'is_overage_billed',
        'stripe_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'is_current' => 'boolean',
            'is_overage_billed' => 'boolean',
            'credits_limit' => 'integer',
            'credits_from_packs' => 'integer',
            'credits_rolled_over' => 'integer',
            'credits_used' => 'integer',
            'credits_overage' => 'integer',
            'executions_total' => 'integer',
            'executions_succeeded' => 'integer',
            'executions_failed' => 'integer',
            'nodes_executed' => 'integer',
            'ai_nodes_executed' => 'integer',
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
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasMany<CreditTransaction, $this>
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function totalAvailable(): int
    {
        return $this->credits_limit + $this->credits_from_packs + $this->credits_rolled_over;
    }

    public function creditsRemaining(): int
    {
        return max(0, $this->totalAvailable() - $this->credits_used);
    }

    public function isExhausted(): bool
    {
        return $this->creditsRemaining() <= 0;
    }
}
