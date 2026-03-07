<?php

namespace Database\Factories;

use App\Models\UsageDailySnapshot;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageDailySnapshot>
 */
class UsageDailySnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'snapshot_date' => fake()->dateTimeBetween('-30 days', 'yesterday')->format('Y-m-d'),
            'credits_used' => fake()->numberBetween(0, 500),
            'executions_total' => fake()->numberBetween(0, 100),
            'executions_succeeded' => fake()->numberBetween(0, 80),
            'executions_failed' => fake()->numberBetween(0, 20),
            'nodes_executed' => fake()->numberBetween(0, 1000),
            'ai_nodes_executed' => fake()->numberBetween(0, 50),
        ];
    }
}
