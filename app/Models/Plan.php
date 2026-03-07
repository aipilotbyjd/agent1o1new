<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /** @use HasFactory<\Database\Factories\PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_monthly',
        'price_yearly',
        'limits',
        'features',
        'stripe_product_id',
        'stripe_prices',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'features' => 'array',
            'stripe_prices' => 'array',
            'is_active' => 'boolean',
            'price_monthly' => 'integer',
            'price_yearly' => 'integer',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function getLimit(string $key): mixed
    {
        return $this->limits[$key] ?? null;
    }

    public function hasFeature(string $key): bool
    {
        return (bool) ($this->features[$key] ?? false);
    }
}
