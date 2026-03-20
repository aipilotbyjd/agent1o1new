<?php

use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use App\Enums\Role;
use App\Jobs\ExecuteWorkflowJob;
use App\Models\Execution;
use App\Models\ExecutionLog;
use App\Models\ExecutionNode;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────

function setupExecutionWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    $workspace->members()->attach($owner->id, [
        'role' => Role::Owner->value,
        'joined_at' => now(),
    ]);

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'is_active' => true,
    ]);

    $version = WorkflowVersion::factory()->published()->create([
        'workflow_id' => $workflow->id,
        'created_by' => $owner->id,
    ]);

    $workflow->update(['current_version_id' => $version->id]);

    return [$owner, $workspace, $workflow];
}

function createExecution(Workspace $workspace, Workflow $workflow, User $owner, array $overrides = []): Execution
{
    return Execution::factory()->create([
        'workspace_id' => $workspace->id,
        'workflow_id' => $workflow->id,
        'triggered_by' => $owner->id,
        ...$overrides,
    ]);
}

// ── Trigger Execution ────────────────────────────────────────

test('owner can trigger a manual execution', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/execute", [
            'trigger_data' => ['email' => 'test@example.com'],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.mode', 'manual');

    $this->assertDatabaseHas('executions', [
        'workflow_id' => $workflow->id,
        'workspace_id' => $workspace->id,
        'status' => 'pending',
    ]);
});

test('triggering dispatches ExecuteWorkflowJob', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();

    $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/execute")
        ->assertStatus(201);

    Queue::assertPushed(ExecuteWorkflowJob::class);
});

test('cannot trigger inactive workflow', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $workflow->update(['is_active' => false]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/execute");

    $response->assertStatus(422);
    Queue::assertNothingPushed();
});

test('cannot trigger workflow without published version', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $workflow->update(['current_version_id' => null]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/execute");

    $response->assertStatus(422);
});

// ── Execution History ────────────────────────────────────────

test('owner can list executions', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    createExecution($workspace, $workflow, $owner);
    createExecution($workspace, $workflow, $owner);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/executions");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can filter executions by status', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    createExecution($workspace, $workflow, $owner, ['status' => ExecutionStatus::Completed]);
    createExecution($workspace, $workflow, $owner, ['status' => ExecutionStatus::Failed]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/executions?status=completed");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('can filter executions by workflow_id', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $otherWorkflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
    ]);
    createExecution($workspace, $workflow, $owner);
    createExecution($workspace, $otherWorkflow, $owner);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/executions?workflow_id={$workflow->id}");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('can filter executions by mode', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    createExecution($workspace, $workflow, $owner, ['mode' => ExecutionMode::Manual]);
    createExecution($workspace, $workflow, $owner, ['mode' => ExecutionMode::Webhook]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/executions?mode=manual");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('owner can view execution detail with nodes', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $execution = createExecution($workspace, $workflow, $owner);
    ExecutionNode::factory()->completed()->create([
        'execution_id' => $execution->id,
        'node_id' => 'trigger_1',
        'sequence' => 1,
    ]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/executions/{$execution->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $execution->id)
        ->assertJsonCount(1, 'data.nodes');
});

test('owner can list execution nodes', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $execution = createExecution($workspace, $workflow, $owner);
    ExecutionNode::factory()->count(3)->create(['execution_id' => $execution->id]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/executions/{$execution->id}/nodes");

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('owner can list execution logs', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $execution = createExecution($workspace, $workflow, $owner);
    ExecutionLog::create([
        'execution_id' => $execution->id,
        'level' => 'info',
        'message' => 'Started execution',
        'logged_at' => now(),
    ]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/executions/{$execution->id}/logs");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('owner can delete an execution', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $execution = createExecution($workspace, $workflow, $owner);

    $response = $this->actingAs($owner, 'api')
        ->deleteJson("/api/v1/workspaces/{$workspace->id}/executions/{$execution->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('executions', ['id' => $execution->id]);
});

// ── Stats ────────────────────────────────────────────────────

test('can get execution stats for workspace', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    createExecution($workspace, $workflow, $owner, ['status' => ExecutionStatus::Completed, 'duration_ms' => 1000]);
    createExecution($workspace, $workflow, $owner, ['status' => ExecutionStatus::Completed, 'duration_ms' => 3000]);
    createExecution($workspace, $workflow, $owner, ['status' => ExecutionStatus::Failed]);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/executions/stats");

    $response->assertOk();
    $data = $response->json('data');

    expect($data['total'])->toBe(3);
    expect($data['completed'])->toBe(2);
    expect($data['failed'])->toBe(1);
    expect($data['success_rate'])->toBe(66.67);
    expect($data['avg_duration_ms'])->toBe(2000);
});

// ── Per-Workflow Executions ──────────────────────────────────

test('can list executions for a specific workflow', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    createExecution($workspace, $workflow, $owner);

    $response = $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/executions");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

// ── Retry & Cancel ───────────────────────────────────────────

test('owner can retry a failed execution', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $execution = createExecution($workspace, $workflow, $owner, [
        'status' => ExecutionStatus::Failed,
        'attempt' => 1,
        'max_attempts' => 3,
    ]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/executions/{$execution->id}/retry");

    $response->assertStatus(201)
        ->assertJsonPath('data.attempt', 2)
        ->assertJsonPath('data.parent_execution_id', $execution->id);

    Queue::assertPushed(ExecuteWorkflowJob::class);
});

test('cannot retry a non-failed execution', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $execution = createExecution($workspace, $workflow, $owner, ['status' => ExecutionStatus::Completed]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/executions/{$execution->id}/retry");

    $response->assertStatus(422);
});

test('cannot retry if max_attempts reached', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $execution = createExecution($workspace, $workflow, $owner, [
        'status' => ExecutionStatus::Failed,
        'attempt' => 3,
        'max_attempts' => 3,
    ]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/executions/{$execution->id}/retry");

    $response->assertStatus(422);
});

test('owner can cancel an active execution', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $execution = createExecution($workspace, $workflow, $owner, [
        'status' => ExecutionStatus::Running,
        'started_at' => now(),
    ]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/executions/{$execution->id}/cancel");

    $response->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('cannot cancel a terminal execution', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $execution = createExecution($workspace, $workflow, $owner, ['status' => ExecutionStatus::Completed]);

    $response = $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/executions/{$execution->id}/cancel");

    $response->assertStatus(422);
});

// ── Authorization ────────────────────────────────────────────

test('viewer can view executions but cannot trigger', function () {
    Queue::fake();
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value, 'joined_at' => now()]);
    createExecution($workspace, $workflow, $owner);

    $this->actingAs($viewer, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/executions")
        ->assertOk();

    $this->actingAs($viewer, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/workflows/{$workflow->id}/execute")
        ->assertStatus(403);
});

test('non-member cannot access executions', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/executions")
        ->assertStatus(403);
});

// ── Removed External Engine API Surface ──────────────────────

test('legacy engine callback endpoints are not registered', function () {
    $this->postJson('/api/v1/jobs/callback', [])->assertNotFound();
    $this->postJson('/api/v1/jobs/progress', [])->assertNotFound();
});

test('legacy internal engine endpoints are not registered', function () {
    $this->postJson('/api/v1/internal/credentials', [])->assertNotFound();
    $this->postJson('/api/v1/internal/workflows/definition', [])->assertNotFound();
});

test('legacy workspace engine dashboard endpoints are not registered', function () {
    [$owner, $workspace, $workflow] = setupExecutionWorkspace();
    $execution = createExecution($workspace, $workflow, $owner);

    $this->actingAs($owner, 'api')
        ->getJson("/api/v1/workspaces/{$workspace->id}/engine/health")
        ->assertNotFound();

    $this->actingAs($owner, 'api')
        ->postJson("/api/v1/workspaces/{$workspace->id}/executions/{$execution->id}/pause-engine")
        ->assertNotFound();
});
