<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-30 days', 'now');

        return [
            'workspace_id' => Workspace::factory(),
            'plan_id' => Plan::factory(),
            'stripe_subscription_id' => null,
            'stripe_customer_id' => null,
            'stripe_price_id' => null,
            'status' => SubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Monthly,
            'credits_monthly' => 1000,
            'trial_ends_at' => null,
            'current_period_start' => $start,
            'current_period_end' => now()->addDays(30),
            'canceled_at' => null,
        ];
    }

    /**
     * Set the subscription to trialing status.
     */
    public function trialing(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    /**
     * Set the subscription to canceled status.
     */
    public function canceled(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => now(),
        ]);
    }

    /**
     * Set the subscription to past due status.
     */
    public function pastDue(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::PastDue,
        ]);
    }

    /**
     * Set the subscription to yearly billing.
     */
    public function yearly(): static
    {
        return $this->state(fn () => [
            'billing_interval' => BillingInterval::Yearly,
        ]);
    }
}
