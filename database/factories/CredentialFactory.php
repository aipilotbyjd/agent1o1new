<?php

namespace Database\Factories;

use App\Models\Credential;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Credential>
 */
class CredentialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => 'http_basic',
            'data' => json_encode(['username' => 'test_user', 'password' => 'secret_pass']),
            'last_used_at' => null,
            'expires_at' => null,
        ];
    }

    /**
     * Mark the credential as expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
