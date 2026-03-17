<?php

use App\Engine\WorkflowEngine;
use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use App\Jobs\ResumeWorkflowJob;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ── Helpers ─────────────────────────────────────────────────

function suspendSetup(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    return [$owner, $workspace];
}

function suspendWorkflow(
    Workspace $workspace,
    User $owner,
    array $nodes,
    array $edges,
): Workflow {
    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'is_active' => true,
    ]);

    $version = WorkflowVersion::factory()->create([
        'workflow_id' => $workflow->id,
        'nodes' => $nodes,
        'edges' => $edges,
        'settings' => [],
        'is_published' => true,
        'published_at' => now(),
        'version_number' => 1,
        'created_by' => $owner->id,
    ]);

    $workflow->update(['current_version_id' => $version->id]);

    return $workflow->fresh();
}

function suspendExecution(Workflow $workflow, User $owner): Execution
{
    return Execution::create([
        'workflow_id' => $workflow->id,
        'workspace_id' => $workflow->workspace_id,
        'status' => ExecutionStatus::Pending,
        'mode' => ExecutionMode::Manual,
        'triggered_by' => $owner->id,
        'trigger_data' => ['test' => true],
        'attempt' => 1,
        'max_attempts' => 1,
    ]);
}

function suspendNodes(array $definitions): array
{
    return array_map(fn ($def) => [
        'id' => $def['id'],
        'type' => $def['type'] ?? 'transform',
        'name' => $def['name'] ?? $def['id'],
        'data' => $def['data'] ?? [],
        'position' => $def['position'] ?? ['x' => 0, 'y' => 0],
    ], $definitions);
}

function suspendEdges(array $pairs): array
{
    return array_map(fn ($pair) => [
        'source' => $pair[0],
        'target' => $pair[1],
        'sourceHandle' => $pair[2] ?? 'output',
        'targetHandle' => $pair[3] ?? 'input',
    ], $pairs);
}

// ── Delay Node Suspension ───────────────────────────────────

test('delay node suspends execution and dispatches resume job', function () {
    Queue::fake();

    [$owner, $workspace] = suspendSetup();

    $workflow = suspendWorkflow($workspace, $owner,
        nodes: suspendNodes([
            ['id' => 'trigger_1', 'type' => 'trigger'],
            ['id' => 'delay_1', 'type' => 'delay', 'data' => ['delay_seconds' => 30]],
            ['id' => 'transform_1', 'type' => 'transform', 'data' => ['mode' => 'passthrough']],
        ]),
        edges: suspendEdges([
            ['trigger_1', 'delay_1'],
            ['delay_1', 'transform_1'],
        ]),
    );

    $execution = suspendExecution($workflow, $owner);

    app(WorkflowEngine::class)->run($execution);

    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Waiting)
        ->and($execution->result_data['suspend_reason'])->toBe('delay');

    // Checkpoint should be persisted
    $this->assertDatabaseHas('execution_checkpoints', [
        'execution_id' => $execution->id,
        'suspend_reason' => 'delay',
    ]);

    // Resume job should be dispatched
    Queue::assertPushed(ResumeWorkflowJob::class, function ($job) use ($execution) {
        return $job->execution->id === $execution->id;
    });

    // Trigger and delay nodes should be recorded
    $this->assertDatabaseHas('execution_nodes', [
        'execution_id' => $execution->id,
        'node_id' => 'trigger_1',
        'status' => 'completed',
    ]);

    $this->assertDatabaseHas('execution_nodes', [
        'execution_id' => $execution->id,
        'node_id' => 'delay_1',
        'status' => 'completed',
    ]);

    // Transform should NOT have run yet
    $this->assertDatabaseMissing('execution_nodes', [
        'execution_id' => $execution->id,
        'node_id' => 'transform_1',
    ]);
});

test('resume continues execution after checkpoint', function () {
    Queue::fake();

    [$owner, $workspace] = suspendSetup();

    $workflow = suspendWorkflow($workspace, $owner,
        nodes: suspendNodes([
            ['id' => 'trigger_1', 'type' => 'trigger'],
            ['id' => 'delay_1', 'type' => 'delay', 'data' => ['delay_seconds' => 1]],
            ['id' => 'transform_1', 'type' => 'transform', 'data' => ['mode' => 'passthrough']],
        ]),
        edges: suspendEdges([
            ['trigger_1', 'delay_1'],
            ['delay_1', 'transform_1'],
        ]),
    );

    $execution = suspendExecution($workflow, $owner);
    $engine = app(WorkflowEngine::class);

    // Phase 1: run until suspension
    $engine->run($execution);
    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Waiting);

    // Phase 2: resume from checkpoint
    $engine->resume($execution);
    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Completed)
        ->and($execution->result_data['completed_nodes'])->toBe(3);

    // Transform should now have run
    $this->assertDatabaseHas('execution_nodes', [
        'execution_id' => $execution->id,
        'node_id' => 'transform_1',
        'status' => 'completed',
    ]);

    // Checkpoint should be cleaned up
    $this->assertDatabaseMissing('execution_checkpoints', [
        'execution_id' => $execution->id,
    ]);
});

test('resume fails gracefully with no checkpoint', function () {
    [$owner, $workspace] = suspendSetup();

    $workflow = suspendWorkflow($workspace, $owner,
        nodes: suspendNodes([
            ['id' => 'trigger_1', 'type' => 'trigger'],
        ]),
        edges: [],
    );

    $execution = suspendExecution($workflow, $owner);
    $execution->update(['status' => ExecutionStatus::Waiting]);

    app(WorkflowEngine::class)->resume($execution);

    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Failed)
        ->and($execution->error['message'])->toContain('No checkpoint');
});

// ── ResumeWorkflowJob ───────────────────────────────────────

test('ResumeWorkflowJob skips execution that is no longer waiting', function () {
    [$owner, $workspace] = suspendSetup();

    $workflow = suspendWorkflow($workspace, $owner,
        nodes: suspendNodes([
            ['id' => 'trigger_1', 'type' => 'trigger'],
        ]),
        edges: [],
    );

    $execution = suspendExecution($workflow, $owner);
    $execution->update(['status' => ExecutionStatus::Cancelled]);

    $job = new ResumeWorkflowJob($execution);
    $job->handle(app(WorkflowEngine::class));

    $execution->refresh();

    // Should remain cancelled, not fail or change state
    expect($execution->status)->toBe(ExecutionStatus::Cancelled);
});

test('delay node with zero seconds still suspends and resumes immediately', function () {
    Queue::fake();

    [$owner, $workspace] = suspendSetup();

    $workflow = suspendWorkflow($workspace, $owner,
        nodes: suspendNodes([
            ['id' => 'trigger_1', 'type' => 'trigger'],
            ['id' => 'delay_1', 'type' => 'delay', 'data' => ['delay_seconds' => 0]],
            ['id' => 'transform_1', 'type' => 'transform', 'data' => ['mode' => 'passthrough']],
        ]),
        edges: suspendEdges([
            ['trigger_1', 'delay_1'],
            ['delay_1', 'transform_1'],
        ]),
    );

    $execution = suspendExecution($workflow, $owner);

    app(WorkflowEngine::class)->run($execution);

    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Waiting);

    Queue::assertPushed(ResumeWorkflowJob::class);

    // Resume immediately
    app(WorkflowEngine::class)->resume($execution);
    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Completed);
});
