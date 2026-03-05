<?php

namespace Database\Factories;

use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Execution>
 */
class ExecutionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'workspace_id' => Workspace::factory(),
            'status' => ExecutionStatus::Pending,
            'mode' => ExecutionMode::Manual,
            'triggered_by' => User::factory(),
            'attempt' => 1,
            'max_attempts' => 1,
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionStatus::Completed,
            'started_at' => now()->subSeconds(3),
            'finished_at' => now(),
            'duration_ms' => 3000,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionStatus::Failed,
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
            'duration_ms' => 2000,
            'error' => ['message' => 'Something went wrong'],
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionStatus::Cancelled,
            'started_at' => now()->subSeconds(1),
            'finished_at' => now(),
            'duration_ms' => 1000,
        ]);
    }

    public function waiting(): static
    {
        return $this->state(fn () => [
            'status' => ExecutionStatus::Waiting,
            'started_at' => now(),
        ]);
    }
}
