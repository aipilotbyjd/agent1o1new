<?php

namespace Database\Factories;

use App\Models\Execution;
use App\Models\ExecutionNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExecutionNode>
 */
class ExecutionNodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'execution_id' => Execution::factory(),
            'node_id' => 'node_'.fake()->unique()->numberBetween(1, 1000),
            'node_type' => fake()->randomElement(['trigger_webhook', 'action_http_request', 'action_transform', 'logic_if']),
            'node_name' => fake()->words(2, true),
            'status' => 'pending',
            'sequence' => 1,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
            'duration_ms' => 2000,
            'output_data' => ['result' => 'ok'],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'duration_ms' => 1000,
            'error' => ['message' => 'Node failed'],
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn () => [
            'status' => 'skipped',
        ]);
    }
}
