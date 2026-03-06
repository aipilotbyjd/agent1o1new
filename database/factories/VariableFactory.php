<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Variable;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variable>
 */
class VariableFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'key' => fake()->unique()->word(),
            'value' => fake()->sentence(),
            'description' => fake()->optional()->sentence(),
            'is_secret' => false,
        ];
    }

    /**
     * Mark the variable as secret.
     */
    public function secret(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_secret' => true,
        ]);
    }
}
