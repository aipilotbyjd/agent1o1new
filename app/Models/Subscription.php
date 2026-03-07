<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    /** @use HasFactory<\Database\Factories\SubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'plan_id',
        'stripe_subscription_id',
        'stripe_customer_id',
        'stripe_price_id',
        'status',
        'billing_interval',
        'credits_monthly',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_interval' => BillingInterval::class,
            'credits_monthly' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'canceled_at' => 'datetime',
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
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<WorkspaceUsagePeriod, $this>
     */
    public function usagePeriods(): HasMany
    {
        return $this->hasMany(WorkspaceUsagePeriod::class);
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }

    public function isUsable(): bool
    {
        return in_array($this->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing]);
    }

    public function onTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing && $this->trial_ends_at?->isFuture();
    }
}
