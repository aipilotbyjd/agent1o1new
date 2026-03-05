<?php

namespace Database\Factories;

use App\Models\Node;
use App\Models\NodeCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Node>
 */
class NodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => NodeCategory::factory(),
            'type' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'icon' => fake()->randomElement(['send', 'globe', 'code', 'mail', 'database', 'clock', 'filter', 'zap']),
            'color' => fake()->hexColor(),
            'node_kind' => fake()->randomElement(['trigger', 'action', 'logic', 'transform']),
            'config_schema' => ['properties' => [], 'required' => []],
            'input_schema' => null,
            'output_schema' => null,
            'credential_type' => null,
            'cost_hint_usd' => null,
            'latency_hint_ms' => null,
            'is_active' => true,
            'is_premium' => false,
            'docs_url' => null,
        ];
    }

    /**
     * Mark the node as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Mark the node as premium.
     */
    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_premium' => true,
        ]);
    }

    /**
     * Set node kind to trigger.
     */
    public function trigger(): static
    {
        return $this->state(fn (array $attributes) => [
            'node_kind' => 'trigger',
        ]);
    }

    /**
     * Set node kind to action.
     */
    public function action(): static
    {
        return $this->state(fn (array $attributes) => [
            'node_kind' => 'action',
        ]);
    }
}
