<?php

use App\Engine\Data\OutputBuffer;
use App\Engine\NodeResult;
use App\Engine\RunContext;
use App\Engine\Runners\AsyncRunner;
use App\Engine\Runners\NodePayload;
use App\Engine\Runners\NodePayloadFactory;
use App\Engine\WorkflowGraph;

// ── Helpers ─────────────────────────────────────────────────

function buildTestGraph(array $nodes): WorkflowGraph
{
    $nodeMap = [];
    $successors = [];
    $predecessors = [];
    $inDegree = [];
    $startNodes = [];

    foreach ($nodes as $id => $def) {
        $nodeMap[$id] = $def;
        $successors[$id] = [];
        $predecessors[$id] = [];
        $inDegree[$id] = 0;
    }

    foreach ($inDegree as $id => $degree) {
        if ($degree === 0) {
            $startNodes[] = $id;
        }
    }

    return new WorkflowGraph(
        nodeMap: $nodeMap,
        successors: $successors,
        predecessors: $predecessors,
        inDegree: $inDegree,
        startNodes: $startNodes,
        compiledExpressions: [],
        downstreamConsumers: [],
        edgeMap: [],
    );
}

function buildTestContext(WorkflowGraph $graph): RunContext
{
    return new RunContext(
        graph: $graph,
        outputs: new OutputBuffer(executionId: 1),
        executionId: 1,
        variables: [],
        credentials: [],
    );
}

// ── Tests ───────────────────────────────────────────────────

test('empty batch returns empty results', function () {
    $factory = Mockery::mock(NodePayloadFactory::class);
    $runner = new AsyncRunner($factory, maxConcurrency: 4);

    $graph = buildTestGraph(['n1' => ['type' => 'trigger', 'name' => 'N1']]);
    $context = buildTestContext($graph);

    $results = $runner->runBatch([], $graph, $context);

    expect($results)->toBe([]);
});

test('single node batch runs inline and returns completed result', function () {
    $factory = Mockery::mock(NodePayloadFactory::class);
    $factory->shouldReceive('build')
        ->once()
        ->andReturn(new NodePayload(
            nodeId: 'n1',
            nodeType: 'trigger',
            nodeName: 'Start',
            config: [],
            inputData: [],
        ));

    $runner = new AsyncRunner($factory, maxConcurrency: 4);
    $graph = buildTestGraph(['n1' => ['type' => 'trigger', 'name' => 'Start']]);
    $context = buildTestContext($graph);

    $results = $runner->runBatch(['n1'], $graph, $context);

    expect($results)->toHaveKey('n1')
        ->and($results['n1'])->toBeInstanceOf(NodeResult::class);
});

test('single node returns failed result for unknown type', function () {
    $factory = Mockery::mock(NodePayloadFactory::class);
    $factory->shouldReceive('build')
        ->once()
        ->andReturn(new NodePayload(
            nodeId: 'n1',
            nodeType: 'nonexistent_xyz_type',
            nodeName: 'Bad Node',
            config: [],
            inputData: [],
        ));

    $runner = new AsyncRunner($factory, maxConcurrency: 4);
    $graph = buildTestGraph(['n1' => ['type' => 'nonexistent_xyz_type', 'name' => 'Bad']]);
    $context = buildTestContext($graph);

    $results = $runner->runBatch(['n1'], $graph, $context);

    expect($results)->toHaveKey('n1')
        ->and($results['n1']->isSuccessful())->toBeFalse()
        ->and($results['n1']->error['code'])->toBe('UNKNOWN_TYPE');
});

test('multi-node batch builds all payloads before execution', function () {
    $buildOrder = [];

    $factory = Mockery::mock(NodePayloadFactory::class);
    $factory->shouldReceive('build')
        ->times(3)
        ->andReturnUsing(function (string $nodeId) use (&$buildOrder) {
            $buildOrder[] = $nodeId;

            return new NodePayload(
                nodeId: $nodeId,
                nodeType: 'nonexistent_xyz_type',
                nodeName: $nodeId,
                config: [],
                inputData: [],
            );
        });

    $runner = new AsyncRunner($factory, maxConcurrency: 4);
    $graph = buildTestGraph([
        'n1' => ['type' => 'nonexistent_xyz_type', 'name' => 'N1'],
        'n2' => ['type' => 'nonexistent_xyz_type', 'name' => 'N2'],
        'n3' => ['type' => 'nonexistent_xyz_type', 'name' => 'N3'],
    ]);
    $context = buildTestContext($graph);

    $results = $runner->runBatch(['n1', 'n2', 'n3'], $graph, $context);

    // All payloads were built
    expect($buildOrder)->toBe(['n1', 'n2', 'n3'])
        // All nodes got results (will be failed since type doesn't exist, but that's the fallback)
        ->and($results)->toHaveCount(3)
        ->and($results)->toHaveKeys(['n1', 'n2', 'n3']);
});

test('multi-node batch returns results for every node even on concurrency failure', function () {
    $factory = Mockery::mock(NodePayloadFactory::class);
    $factory->shouldReceive('build')
        ->andReturnUsing(fn (string $nodeId) => new NodePayload(
            nodeId: $nodeId,
            nodeType: 'nonexistent_xyz_type',
            nodeName: $nodeId,
            config: [],
            inputData: [],
        ));

    $runner = new AsyncRunner($factory, maxConcurrency: 4);
    $graph = buildTestGraph([
        'a' => ['type' => 'nonexistent_xyz_type', 'name' => 'A'],
        'b' => ['type' => 'nonexistent_xyz_type', 'name' => 'B'],
    ]);
    $context = buildTestContext($graph);

    // The concurrency driver will fail (or fallback), but every node should still get a result
    $results = $runner->runBatch(['a', 'b'], $graph, $context);

    expect($results)->toHaveCount(2)
        ->and($results)->toHaveKeys(['a', 'b']);

    foreach ($results as $result) {
        expect($result)->toBeInstanceOf(NodeResult::class);
    }
});
