<?php

namespace Database\Factories;

use App\Enums\CreditPackStatus;
use App\Models\CreditPack;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditPack>
 */
class CreditPackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $credits = fake()->randomElement([5000, 10000, 50000]);

        return [
            'workspace_id' => Workspace::factory(),
            'purchased_by' => User::factory(),
            'credits_amount' => $credits,
            'credits_remaining' => $credits,
            'price_cents' => $credits * 0.5,
            'currency' => 'usd',
            'stripe_payment_intent_id' => null,
            'status' => CreditPackStatus::Active,
            'purchased_at' => now(),
            'expires_at' => now()->addYear(),
        ];
    }

    /**
     * Mark the pack as fully exhausted.
     */
    public function exhausted(): static
    {
        return $this->state(fn () => [
            'credits_remaining' => 0,
            'status' => CreditPackStatus::Exhausted,
        ]);
    }

    /**
     * Mark the pack as expired.
     */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => CreditPackStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Mark the pack as pending payment.
     */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => CreditPackStatus::Pending,
        ]);
    }
}
