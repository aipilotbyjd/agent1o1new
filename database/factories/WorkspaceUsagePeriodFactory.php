<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceUsagePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceUsagePeriod>
 */
class WorkspaceUsagePeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'subscription_id' => Subscription::factory(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'credits_limit' => 1000,
            'credits_from_packs' => 0,
            'credits_rolled_over' => 0,
            'credits_used' => 0,
            'credits_overage' => 0,
            'executions_total' => 0,
            'executions_succeeded' => 0,
            'executions_failed' => 0,
            'nodes_executed' => 0,
            'ai_nodes_executed' => 0,
            'is_current' => true,
            'is_overage_billed' => false,
            'stripe_invoice_id' => null,
        ];
    }

    /**
     * Set credits as fully exhausted.
     */
    public function exhausted(): static
    {
        return $this->state(fn () => [
            'credits_used' => 1000,
            'credits_limit' => 1000,
        ]);
    }

    /**
     * Populate with random usage data.
     */
    public function withUsage(): static
    {
        return $this->state(function () {
            $succeeded = fake()->numberBetween(20, 80);
            $failed = fake()->numberBetween(1, 10);

            return [
                'credits_used' => fake()->numberBetween(100, 500),
                'executions_total' => $succeeded + $failed,
                'executions_succeeded' => $succeeded,
                'executions_failed' => $failed,
                'nodes_executed' => fake()->numberBetween(100, 1000),
            ];
        });
    }
}
