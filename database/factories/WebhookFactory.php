<?php

namespace Database\Factories;

use App\Models\Webhook;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'workspace_id' => Workspace::factory(),
            'uuid' => (string) Str::uuid(),
            'path' => fake()->optional()->slug(2),
            'methods' => ['POST'],
            'is_active' => true,
            'auth_type' => 'none',
            'auth_config' => null,
            'rate_limit' => null,
            'response_mode' => 'immediate',
            'response_status' => 200,
            'response_body' => null,
            'call_count' => 0,
            'last_called_at' => null,
        ];
    }

    /**
     * Mark the webhook as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    /**
     * Set auth type to bearer token.
     */
    public function withBearerAuth(string $token = 'test-token'): static
    {
        return $this->state(fn () => [
            'auth_type' => 'bearer',
            'auth_config' => ['token' => $token],
        ]);
    }

    /**
     * Set response mode to wait (waits for execution to finish).
     */
    public function waitMode(): static
    {
        return $this->state(fn () => [
            'response_mode' => 'wait',
        ]);
    }
}
