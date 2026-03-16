<?php

use App\Engine\WorkflowEngine;
use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ─────────────────────────────────────────────────

function setupEngineWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    return [$owner, $workspace];
}

function createWorkflowWithVersion(
    Workspace $workspace,
    User $owner,
    array $nodes,
    array $edges,
    array $settings = [],
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
        'settings' => $settings,
        'is_published' => true,
        'published_at' => now(),
        'version_number' => 1,
        'created_by' => $owner->id,
    ]);

    $workflow->update(['current_version_id' => $version->id]);

    return $workflow->fresh();
}

function createEngineExecution(Workflow $workflow, User $owner): Execution
{
    return Execution::create([
        'workflow_id' => $workflow->id,
        'workspace_id' => $workflow->workspace_id,
        'status' => ExecutionStatus::Pending,
        'mode' => ExecutionMode::Manual,
        'triggered_by' => $owner->id,
        'trigger_data' => ['test' => true, 'message' => 'Hello'],
        'attempt' => 1,
        'max_attempts' => 1,
    ]);
}

function engineNodes(array $definitions): array
{
    return array_map(fn ($def) => [
        'id' => $def['id'],
        'type' => $def['type'] ?? 'transform',
        'name' => $def['name'] ?? $def['id'],
        'data' => $def['data'] ?? [],
        'position' => $def['position'] ?? ['x' => 0, 'y' => 0],
    ], $definitions);
}

function engineEdges(array $pairs): array
{
    return array_map(fn ($pair) => [
        'source' => $pair[0],
        'target' => $pair[1],
        'sourceHandle' => $pair[2] ?? 'output',
        'targetHandle' => $pair[3] ?? 'input',
    ], $pairs);
}

// ── Basic execution ─────────────────────────────────────────

test('executes a single trigger node workflow', function () {
    [$owner, $workspace] = setupEngineWorkspace();

    $workflow = createWorkflowWithVersion($workspace, $owner,
        nodes: engineNodes([
            ['id' => 'trigger_1', 'type' => 'trigger'],
        ]),
        edges: [],
    );

    $execution = createEngineExecution($workflow, $owner);

    app(WorkflowEngine::class)->run($execution);

    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Completed)
        ->and($execution->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($execution->result_data['completed_nodes'])->toBe(1);

    $this->assertDatabaseHas('execution_nodes', [
        'execution_id' => $execution->id,
        'node_id' => 'trigger_1',
        'status' => 'completed',
    ]);
});

test('executes a linear trigger → transform workflow', function () {
    [$owner, $workspace] = setupEngineWorkspace();

    $workflow = createWorkflowWithVersion($workspace, $owner,
        nodes: engineNodes([
            ['id' => 'trigger_1', 'type' => 'trigger'],
            ['id' => 'transform_1', 'type' => 'transform', 'data' => [
                'mode' => 'passthrough',
            ]],
        ]),
        edges: engineEdges([
            ['trigger_1', 'transform_1'],
        ]),
    );

    $execution = createEngineExecution($workflow, $owner);

    app(WorkflowEngine::class)->run($execution);

    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Completed)
        ->and($execution->result_data['completed_nodes'])->toBe(2);

    $this->assertDatabaseHas('execution_nodes', [
        'execution_id' => $execution->id,
        'node_id' => 'trigger_1',
        'status' => 'completed',
    ]);

    $this->assertDatabaseHas('execution_nodes', [
        'execution_id' => $execution->id,
        'node_id' => 'transform_1',
        'status' => 'completed',
    ]);
});

test('executes a three-node linear chain', function () {
    [$owner, $workspace] = setupEngineWorkspace();

    $workflow = createWorkflowWithVersion($workspace, $owner,
        nodes: engineNodes([
            ['id' => 'trigger_1', 'type' => 'trigger'],
            ['id' => 'transform_a', 'type' => 'transform', 'data' => ['mode' => 'static', 'output' => ['step' => 'a']]],
            ['id' => 'transform_b', 'type' => 'transform', 'data' => ['mode' => 'passthrough']],
        ]),
        edges: engineEdges([
            ['trigger_1', 'transform_a'],
            ['transform_a', 'transform_b'],
        ]),
    );

    $execution = createEngineExecution($workflow, $owner);

    app(WorkflowEngine::class)->run($execution);

    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Completed)
        ->and($execution->result_data['completed_nodes'])->toBe(3);

    // Verify all 3 nodes have execution_node records
    expect($execution->nodes()->count())->toBe(3);
});

// ── Parallel branches ───────────────────────────────────────

test('executes parallel branches that converge', function () {
    [$owner, $workspace] = setupEngineWorkspace();

    // trigger → [branch_a, branch_b] → merge
    $workflow = createWorkflowWithVersion($workspace, $owner,
        nodes: engineNodes([
            ['id' => 'trigger_1', 'type' => 'trigger'],
            ['id' => 'branch_a', 'type' => 'transform', 'data' => ['mode' => 'static', 'output' => ['branch' => 'a']]],
            ['id' => 'branch_b', 'type' => 'transform', 'data' => ['mode' => 'static', 'output' => ['branch' => 'b']]],
            ['id' => 'final', 'type' => 'transform', 'data' => ['mode' => 'passthrough']],
        ]),
        edges: engineEdges([
            ['trigger_1', 'branch_a'],
            ['trigger_1', 'branch_b'],
            ['branch_a', 'final'],
            ['branch_b', 'final'],
        ]),
    );

    $execution = createEngineExecution($workflow, $owner);

    app(WorkflowEngine::class)->run($execution);

    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Completed)
        ->and($execution->result_data['completed_nodes'])->toBe(4);
});

// ── Error handling ──────────────────────────────────────────

test('fails execution when workflow has no published version', function () {
    [$owner, $workspace] = setupEngineWorkspace();

    $workflow = Workflow::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
        'is_active' => true,
        'current_version_id' => null,
    ]);

    $execution = createEngineExecution($workflow, $owner);

    app(WorkflowEngine::class)->run($execution);

    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Failed)
        ->and($execution->error['message'])->toContain('no published version');
});

test('fails execution when workflow has no nodes', function () {
    [$owner, $workspace] = setupEngineWorkspace();

    $workflow = createWorkflowWithVersion($workspace, $owner,
        nodes: [],
        edges: [],
    );

    $execution = createEngineExecution($workflow, $owner);

    app(WorkflowEngine::class)->run($execution);

    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Failed)
        ->and($execution->error['message'])->toContain('no nodes');
});

// ── Execution node records ──────────────────────────────────

test('execution nodes have correct sequence numbers', function () {
    [$owner, $workspace] = setupEngineWorkspace();

    $workflow = createWorkflowWithVersion($workspace, $owner,
        nodes: engineNodes([
            ['id' => 'trigger_1', 'type' => 'trigger'],
            ['id' => 'step_2', 'type' => 'transform', 'data' => ['mode' => 'passthrough']],
            ['id' => 'step_3', 'type' => 'transform', 'data' => ['mode' => 'passthrough']],
        ]),
        edges: engineEdges([
            ['trigger_1', 'step_2'],
            ['step_2', 'step_3'],
        ]),
    );

    $execution = createEngineExecution($workflow, $owner);

    app(WorkflowEngine::class)->run($execution);

    $nodes = $execution->nodes()->orderBy('sequence')->get();

    expect($nodes)->toHaveCount(3)
        ->and($nodes[0]->sequence)->toBe(1)
        ->and($nodes[1]->sequence)->toBe(2)
        ->and($nodes[2]->sequence)->toBe(3);
});

test('execution nodes have node_run_key populated', function () {
    [$owner, $workspace] = setupEngineWorkspace();

    $workflow = createWorkflowWithVersion($workspace, $owner,
        nodes: engineNodes([
            ['id' => 'trigger_1', 'type' => 'trigger'],
        ]),
        edges: [],
    );

    $execution = createEngineExecution($workflow, $owner);

    app(WorkflowEngine::class)->run($execution);

    $this->assertDatabaseHas('execution_nodes', [
        'execution_id' => $execution->id,
        'node_run_key' => 'trigger_1',
    ]);
});

// ── Workflow stats ──────────────────────────────────────────

test('increments workflow execution_count on completion', function () {
    [$owner, $workspace] = setupEngineWorkspace();

    $workflow = createWorkflowWithVersion($workspace, $owner,
        nodes: engineNodes([
            ['id' => 'trigger_1', 'type' => 'trigger'],
        ]),
        edges: [],
    );

    $initialCount = $workflow->execution_count;

    $execution = createEngineExecution($workflow, $owner);

    app(WorkflowEngine::class)->run($execution);

    $workflow->refresh();

    expect($workflow->execution_count)->toBe($initialCount + 1)
        ->and($workflow->last_executed_at)->not->toBeNull();
});
