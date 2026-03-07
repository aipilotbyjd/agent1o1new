<?php

namespace App\Models;

use App\Enums\CreditPackStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditPack extends Model
{
    /** @use HasFactory<\Database\Factories\CreditPackFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'purchased_by',
        'credits_amount',
        'credits_remaining',
        'price_cents',
        'currency',
        'stripe_payment_intent_id',
        'status',
        'purchased_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CreditPackStatus::class,
            'credits_amount' => 'integer',
            'credits_remaining' => 'integer',
            'price_cents' => 'integer',
            'purchased_at' => 'datetime',
            'expires_at' => 'datetime',
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
    public function purchaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchased_by');
    }

    public function isUsable(): bool
    {
        return $this->status === CreditPackStatus::Active && $this->credits_remaining > 0;
    }

    public function consume(int $amount): void
    {
        $this->decrement('credits_remaining', $amount);

        if ($this->credits_remaining <= 0) {
            $this->update(['status' => CreditPackStatus::Exhausted]);
        }
    }
}
