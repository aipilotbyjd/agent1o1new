<?php

namespace Database\Factories;

use App\Models\PollingTrigger;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PollingTrigger>
 */
class PollingTriggerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'workspace_id' => Workspace::factory(),
            'endpoint_url' => fake()->url(),
            'http_method' => 'GET',
            'headers' => null,
            'query_params' => null,
            'body' => null,
            'dedup_key' => 'id',
            'interval_seconds' => 300,
            'is_active' => true,
            'auth_config' => null,
            'last_seen_ids' => null,
            'last_polled_at' => null,
            'next_poll_at' => now(),
            'poll_count' => 0,
            'trigger_count' => 0,
            'last_error' => null,
        ];
    }

    /**
     * Mark the polling trigger as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    /**
     * Set bearer auth credentials.
     */
    public function withBearerAuth(string $token = 'test-token'): static
    {
        return $this->state(fn () => [
            'auth_config' => ['type' => 'bearer', 'token' => $token],
        ]);
    }

    /**
     * Set a custom polling interval.
     */
    public function everyMinute(): static
    {
        return $this->state(fn () => [
            'interval_seconds' => 60,
        ]);
    }
}
