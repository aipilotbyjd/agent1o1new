<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceEnvironment>
 */
class WorkspaceEnvironmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->randomElement(['production', 'staging', 'development', 'testing', 'preview']),
            'git_branch' => fake()->unique()->slug(2),
            'base_branch' => 'main',
            'is_default' => false,
            'is_active' => true,
        ];
    }

    /**
     * Mark the environment as the default.
     */
    public function default(): static
    {
        return $this->state(fn () => [
            'is_default' => true,
        ]);
    }

    /**
     * Mark the environment as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
