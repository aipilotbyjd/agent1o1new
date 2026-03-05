<?php

namespace Database\Factories;

use App\Models\NodeCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NodeCategory>
 */
class NodeCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Triggers', 'Actions', 'Logic', 'Integrations',
            'Transform', 'Communication', 'Data', 'Utilities',
        ]);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'icon' => fake()->randomElement(['zap', 'play', 'git-branch', 'plug', 'shuffle', 'mail', 'database', 'tool']),
            'color' => fake()->hexColor(),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
