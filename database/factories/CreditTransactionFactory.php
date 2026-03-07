<?php

namespace Database\Factories;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Workspace;
use App\Models\WorkspaceUsagePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditTransaction>
 */
class CreditTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'usage_period_id' => WorkspaceUsagePeriod::factory(),
            'type' => CreditTransactionType::Execution,
            'credits' => fake()->numberBetween(1, 10),
            'description' => null,
            'execution_id' => null,
            'execution_node_id' => null,
            'created_at' => now(),
        ];
    }

    /**
     * Mark the transaction as a refund with negative credits.
     */
    public function refund(): static
    {
        return $this->state(fn () => [
            'type' => CreditTransactionType::Refund,
            'credits' => fake()->numberBetween(-10, -1),
        ]);
    }

    /**
     * Mark the transaction as an AI execution.
     */
    public function aiExecution(): static
    {
        return $this->state(fn () => [
            'type' => CreditTransactionType::AiExecution,
            'credits' => 10,
        ]);
    }

    /**
     * Mark the transaction as a pack purchase with negative credits.
     */
    public function packPurchase(): static
    {
        return $this->state(fn () => [
            'type' => CreditTransactionType::PackPurchase,
            'credits' => fake()->randomElement([-5000, -10000, -50000]),
        ]);
    }
}
