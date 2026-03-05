<?php

namespace Database\Factories;

use App\Models\CredentialType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CredentialType>
 */
class CredentialTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'icon' => fake()->randomElement(['key', 'lock', 'shield', 'database', 'cloud', 'server']),
            'color' => fake()->hexColor(),
            'fields_schema' => [
                'properties' => [
                    'api_key' => ['type' => 'string', 'secret' => true, 'required' => true],
                ],
                'required' => ['api_key'],
            ],
            'test_config' => null,
            'docs_url' => null,
        ];
    }
}
