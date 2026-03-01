<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::Member->value,
            'token' => Str::random(64),
            'invited_by' => User::factory(),
            'accepted_at' => null,
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Mark the invitation as expired.
     */
    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Mark the invitation as accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn () => [
            'accepted_at' => now(),
        ]);
    }
}
