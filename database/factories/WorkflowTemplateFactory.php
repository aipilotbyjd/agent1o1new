<?php

namespace Database\Factories;

use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkflowTemplate>
 */
class WorkflowTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement(['automation', 'integration', 'data-processing', 'communication', 'devops']),
            'icon' => fake()->randomElement(['zap', 'play', 'git-branch', 'plug', 'shuffle', 'mail']),
            'color' => fake()->hexColor(),
            'tags' => fake()->randomElements(['popular', 'new', 'beginner', 'advanced', 'enterprise'], 2),
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
            'thumbnail_url' => null,
            'instructions' => fake()->optional()->paragraph(),
            'required_credentials' => [],
            'is_featured' => false,
            'is_active' => true,
            'usage_count' => 0,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * Mark the template as featured.
     */
    public function featured(): static
    {
        return $this->state(fn () => [
            'is_featured' => true,
        ]);
    }

    /**
     * Mark the template as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
