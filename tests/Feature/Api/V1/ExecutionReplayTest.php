<?php

use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use App\Models\Execution;
use App\Models\ExecutionReplayPack;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    $this->user->workspaces()->attach($this->workspace, ['role' => 'admin']);

    $this->workflow = Workflow::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'is_active' => true,
    ]);

    $version = WorkflowVersion::factory()->create([
        'workflow_id' => $this->workflow->id,
        'nodes' => [['id' => 'trigger_1', 'type' => 'trigger', 'name' => 'Start', 'data' => [], 'position' => ['x' => 0, 'y' => 0]]],
        'edges' => [],
        'is_published' => true,
        'published_at' => now(),
        'version_number' => 1,
        'created_by' => $this->user->id,
    ]);

    $this->workflow->update(['current_version_id' => $version->id]);

    $this->actingAs($this->user, 'api');
});

it('replays a completed execution', function () {
    $execution = Execution::create([
        'workflow_id' => $this->workflow->id,
        'workspace_id' => $this->workspace->id,
        'status' => ExecutionStatus::Completed,
        'mode' => ExecutionMode::Manual,
        'triggered_by' => $this->user->id,
        'trigger_data' => ['key' => 'original_value'],
        'attempt' => 1,
        'max_attempts' => 1,
    ]);

    ExecutionReplayPack::create([
        'execution_id' => $execution->id,
        'workspace_id' => $this->workspace->id,
        'workflow_id' => $this->workflow->id,
        'mode' => 'capture',
        'deterministic_seed' => 'test-seed',
        'workflow_snapshot' => ['nodes' => [], 'edges' => []],
        'trigger_snapshot' => ['key' => 'original_value'],
        'fixtures' => [],
        'environment_snapshot' => [],
        'captured_at' => now(),
        'expires_at' => now()->addDays(30),
    ]);

    $response = $this->postJson(
        "/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/replay",
    );

    $response->assertStatus(201)
        ->assertJsonPath('data.mode', 'replay')
        ->assertJsonPath('data.is_deterministic_replay', true);

    $this->assertDatabaseHas('executions', [
        'replay_of_execution_id' => $execution->id,
        'mode' => 'replay',
        'is_deterministic_replay' => true,
    ]);
});

it('rejects replay of execution without replay pack', function () {
    $execution = Execution::create([
        'workflow_id' => $this->workflow->id,
        'workspace_id' => $this->workspace->id,
        'status' => ExecutionStatus::Completed,
        'mode' => ExecutionMode::Manual,
        'triggered_by' => $this->user->id,
        'attempt' => 1,
        'max_attempts' => 1,
    ]);

    $response = $this->postJson(
        "/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/replay",
    );

    $response->assertStatus(422)
        ->assertJsonFragment(['message' => 'No replay pack found for this execution.']);
});

it('rejects replay of an active execution', function () {
    $execution = Execution::create([
        'workflow_id' => $this->workflow->id,
        'workspace_id' => $this->workspace->id,
        'status' => ExecutionStatus::Running,
        'mode' => ExecutionMode::Manual,
        'triggered_by' => $this->user->id,
        'attempt' => 1,
        'max_attempts' => 1,
    ]);

    ExecutionReplayPack::create([
        'execution_id' => $execution->id,
        'workspace_id' => $this->workspace->id,
        'workflow_id' => $this->workflow->id,
        'mode' => 'capture',
        'deterministic_seed' => 'test-seed',
        'workflow_snapshot' => [],
        'trigger_snapshot' => [],
        'fixtures' => [],
        'environment_snapshot' => [],
        'captured_at' => now(),
        'expires_at' => now()->addDays(30),
    ]);

    $response = $this->postJson(
        "/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/replay",
    );

    $response->assertStatus(422)
        ->assertJsonFragment(['message' => 'Cannot replay an active execution.']);
});

it('replays a failed execution', function () {
    $execution = Execution::create([
        'workflow_id' => $this->workflow->id,
        'workspace_id' => $this->workspace->id,
        'status' => ExecutionStatus::Failed,
        'mode' => ExecutionMode::Manual,
        'triggered_by' => $this->user->id,
        'trigger_data' => ['data' => 'test'],
        'attempt' => 1,
        'max_attempts' => 1,
    ]);

    ExecutionReplayPack::create([
        'execution_id' => $execution->id,
        'workspace_id' => $this->workspace->id,
        'workflow_id' => $this->workflow->id,
        'mode' => 'capture',
        'deterministic_seed' => 'test-seed',
        'workflow_snapshot' => ['nodes' => [], 'edges' => []],
        'trigger_snapshot' => ['data' => 'test'],
        'fixtures' => [],
        'environment_snapshot' => [],
        'captured_at' => now(),
        'expires_at' => now()->addDays(30),
    ]);

    $response = $this->postJson(
        "/api/v1/workspaces/{$this->workspace->id}/executions/{$execution->id}/replay",
    );

    $response->assertStatus(201)
        ->assertJsonPath('data.mode', 'replay');
});
