<?php

use App\Engine\Data\OutputBuffer;
use App\Engine\Data\Suspension;
use App\Engine\NodeResult;
use App\Engine\Persistence\CheckpointStore;
use App\Engine\RunContext;
use App\Engine\WorkflowGraph;
use App\Enums\ExecutionStatus;
use App\Models\Execution;
use App\Models\ExecutionCheckpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────

function buildSimpleGraph(): WorkflowGraph
{
    return new WorkflowGraph(
        nodeMap: [
            'a' => ['type' => 'action', 'config' => []],
            'b' => ['type' => 'action', 'config' => []],
        ],
        successors: ['a' => ['b']],
        predecessors: ['b' => ['a']],
        inDegree: ['a' => 0, 'b' => 1],
        startNodes: ['a'],
        compiledExpressions: [],
        downstreamConsumers: ['a' => ['b']],
        edgeMap: ['a' => [['target' => 'b', 'sourceHandle' => 'output', 'targetHandle' => 'input']]],
    );
}

// ── ExecutionCheckpoint Model ────────────────────────────────

it('belongs to an execution', function () {
    $execution = Execution::factory()->create();

    $checkpoint = ExecutionCheckpoint::create([
        'execution_id' => $execution->id,
        'frontier_state' => ['ready_queue' => []],
        'output_refs' => ['outputs' => [], 'ref_counts' => []],
        'next_sequence' => 1,
        'checkpoint_version' => 1,
    ]);

    expect($checkpoint->execution->id)->toBe($execution->id);
});

it('is accessible from execution via checkpoint relationship', function () {
    $execution = Execution::factory()->create();

    ExecutionCheckpoint::create([
        'execution_id' => $execution->id,
        'frontier_state' => ['ready_queue' => ['b']],
        'output_refs' => ['outputs' => [], 'ref_counts' => []],
        'next_sequence' => 2,
        'checkpoint_version' => 1,
    ]);

    expect($execution->checkpoint)->toBeInstanceOf(ExecutionCheckpoint::class)
        ->and($execution->checkpoint->next_sequence)->toBe(2);
});

// ── OutputBuffer snapshot/restore ────────────────────────────

it('snapshots and restores OutputBuffer with ref counts', function () {
    $buffer = new OutputBuffer(
        executionId: 1,
        downstreamConsumers: ['a' => ['b', 'c']],
    );

    $buffer->store('a', ['result' => 'hello']);

    $snapshot = $buffer->snapshot();

    expect($snapshot['outputs']['a'])->toBe(['result' => 'hello'])
        ->and($snapshot['ref_counts']['a'])->toBe(2);

    $restored = OutputBuffer::fromSnapshot(1, $snapshot, ['a' => ['b', 'c']]);

    expect($restored->get('a'))->toBe(['result' => 'hello']);
});

// ── RunContext snapshot ──────────────────────────────────────

it('creates a snapshot of the runtime state', function () {
    $graph = buildSimpleGraph();
    $buffer = new OutputBuffer(executionId: 1, downstreamConsumers: $graph->downstreamConsumers);

    $context = new RunContext(
        graph: $graph,
        outputs: $buffer,
        executionId: 1,
        variables: ['key' => 'value'],
    );

    $context->complete('a', NodeResult::completed(['data' => 42], 10));

    $snapshot = $context->snapshot();

    expect($snapshot['ready_queue'])->toBe(['b'])
        ->and($snapshot['completed_nodes'])->toHaveKey('a')
        ->and($snapshot['variables'])->toBe(['key' => 'value'])
        ->and($snapshot['next_sequence'])->toBe(1)
        ->and($snapshot['remaining_in_degree']['b'])->toBe(0);
});

// ── CheckpointStore ──────────────────────────────────────────

it('saves and loads a checkpoint', function () {
    $execution = Execution::factory()->running()->create();
    $graph = buildSimpleGraph();
    $buffer = new OutputBuffer(executionId: $execution->id, downstreamConsumers: $graph->downstreamConsumers);

    $context = new RunContext(
        graph: $graph,
        outputs: $buffer,
        executionId: $execution->id,
        variables: ['env' => 'test'],
    );

    $context->complete('a', NodeResult::completed(['out' => 1], 5));

    $suspension = new Suspension(reason: 'rate_limit', resumeAt: now()->addMinutes(5));

    $store = new CheckpointStore;
    $store->save($execution, $context, $suspension);

    $loaded = $store->load($execution->id);

    expect($loaded)->toBeInstanceOf(ExecutionCheckpoint::class)
        ->and($loaded->suspend_reason)->toBe('rate_limit')
        ->and($loaded->next_sequence)->toBe(1)
        ->and($loaded->frontier_state['variables'])->toBe(['env' => 'test'])
        ->and($loaded->frontier_state['ready_queue'])->toBe(['b'])
        ->and($loaded->output_refs['outputs'])->toHaveKey('a');
});

it('overwrites existing checkpoint on save', function () {
    $execution = Execution::factory()->running()->create();
    $graph = buildSimpleGraph();
    $buffer = new OutputBuffer(executionId: $execution->id, downstreamConsumers: $graph->downstreamConsumers);

    $context = new RunContext(
        graph: $graph,
        outputs: $buffer,
        executionId: $execution->id,
    );

    $store = new CheckpointStore;

    $store->save($execution, $context, new Suspension(reason: 'first'));
    $store->save($execution, $context, new Suspension(reason: 'second'));

    expect(ExecutionCheckpoint::where('execution_id', $execution->id)->count())->toBe(1)
        ->and($store->load($execution->id)->suspend_reason)->toBe('second');
});

it('deletes a checkpoint', function () {
    $execution = Execution::factory()->running()->create();
    $graph = buildSimpleGraph();
    $buffer = new OutputBuffer(executionId: $execution->id, downstreamConsumers: $graph->downstreamConsumers);

    $context = new RunContext(
        graph: $graph,
        outputs: $buffer,
        executionId: $execution->id,
    );

    $store = new CheckpointStore;
    $store->save($execution, $context, new Suspension(reason: 'wait'));
    $store->delete($execution->id);

    expect($store->load($execution->id))->toBeNull();
});

// ── Execution state transitions ──────────────────────────────

it('transitions to waiting status with meta', function () {
    $execution = Execution::factory()->running()->create();
    $resumeAt = now()->addMinutes(10);

    $execution->markWaiting($resumeAt, ['reason' => 'rate_limit']);

    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Waiting)
        ->and($execution->result_data)->toBe(['reason' => 'rate_limit']);
});

it('transitions from waiting to running on resume', function () {
    $execution = Execution::factory()->waiting()->create();

    $execution->resume();
    $execution->refresh();

    expect($execution->status)->toBe(ExecutionStatus::Running);
});
