<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Workflow>
 */
class WorkflowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'icon' => 'workflow',
            'color' => '#6366f1',
            'is_active' => false,
            'is_locked' => false,
        ];
    }

    /**
     * Mark the workflow as active.
     */
    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    /**
     * Mark the workflow as locked.
     */
    public function locked(): static
    {
        return $this->state(fn () => ['is_locked' => true]);
    }
}
