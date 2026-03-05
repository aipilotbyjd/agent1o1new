<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowVersion>
 */
class WorkflowVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'version_number' => 1,
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'trigger_type' => fake()->randomElement(['manual', 'webhook', 'schedule']),
            'trigger_config' => [],
            'nodes' => [
                [
                    'id' => 'node_1',
                    'type' => 'trigger',
                    'position' => ['x' => 0, 'y' => 0],
                    'data' => ['label' => 'Start'],
                ],
            ],
            'edges' => [],
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
            'settings' => [],
            'created_by' => User::factory(),
            'change_summary' => null,
            'is_published' => false,
            'published_at' => null,
        ];
    }

    /**
     * Mark the version as published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
            'published_at' => now(),
        ]);
    }
}
