<?php

use App\Engine\Data\ExpressionParser;
use App\Engine\Exceptions\CycleDetectedException;
use App\Engine\GraphCompiler;

// ── Helpers ─────────────────────────────────────────────────

function makeNodes(array $definitions): array
{
    return array_map(fn ($def) => [
        'id' => $def['id'],
        'type' => $def['type'] ?? 'transform',
        'name' => $def['name'] ?? $def['id'],
        'data' => $def['data'] ?? [],
        'position' => $def['position'] ?? ['x' => 0, 'y' => 0],
    ], $definitions);
}

function makeEdges(array $pairs): array
{
    return array_map(fn ($pair) => [
        'source' => $pair[0],
        'target' => $pair[1],
        'sourceHandle' => $pair[2] ?? 'output',
        'targetHandle' => $pair[3] ?? 'input',
    ], $pairs);
}

function compiler(): GraphCompiler
{
    return new GraphCompiler(new ExpressionParser);
}

// ── Basic compilation ───────────────────────────────────────

test('compiles a simple linear graph', function () {
    $nodes = makeNodes([
        ['id' => 'trigger', 'type' => 'trigger'],
        ['id' => 'http', 'type' => 'http_request'],
        ['id' => 'output', 'type' => 'transform'],
    ]);

    $edges = makeEdges([
        ['trigger', 'http'],
        ['http', 'output'],
    ]);

    $graph = compiler()->compile($nodes, $edges);

    expect($graph->nodeCount())->toBe(3)
        ->and($graph->startNodes)->toBe(['trigger'])
        ->and($graph->getSuccessors('trigger'))->toBe(['http'])
        ->and($graph->getSuccessors('http'))->toBe(['output'])
        ->and($graph->getSuccessors('output'))->toBe([])
        ->and($graph->getPredecessors('trigger'))->toBe([])
        ->and($graph->getPredecessors('http'))->toBe(['trigger'])
        ->and($graph->getPredecessors('output'))->toBe(['http']);
});

test('computes correct in-degree for each node', function () {
    $nodes = makeNodes([
        ['id' => 'a'],
        ['id' => 'b'],
        ['id' => 'c'],
        ['id' => 'd'],
    ]);

    $edges = makeEdges([
        ['a', 'c'],
        ['b', 'c'],
        ['c', 'd'],
    ]);

    $graph = compiler()->compile($nodes, $edges);

    expect($graph->inDegree)->toBe([
        'a' => 0,
        'b' => 0,
        'c' => 2,
        'd' => 1,
    ]);
});

test('identifies multiple start nodes', function () {
    $nodes = makeNodes([
        ['id' => 'a'],
        ['id' => 'b'],
        ['id' => 'c'],
    ]);

    $edges = makeEdges([
        ['a', 'c'],
        ['b', 'c'],
    ]);

    $graph = compiler()->compile($nodes, $edges);

    expect($graph->startNodes)->toContain('a')
        ->and($graph->startNodes)->toContain('b')
        ->and($graph->startNodes)->toHaveCount(2);
});

// ── Branching ───────────────────────────────────────────────

test('compiles a graph with parallel branches', function () {
    $nodes = makeNodes([
        ['id' => 'trigger', 'type' => 'trigger'],
        ['id' => 'condition', 'type' => 'condition'],
        ['id' => 'branch_a', 'type' => 'http_request'],
        ['id' => 'branch_b', 'type' => 'http_request'],
        ['id' => 'merge', 'type' => 'merge'],
    ]);

    $edges = makeEdges([
        ['trigger', 'condition'],
        ['condition', 'branch_a', 'true'],
        ['condition', 'branch_b', 'false'],
        ['branch_a', 'merge'],
        ['branch_b', 'merge'],
    ]);

    $graph = compiler()->compile($nodes, $edges);

    expect($graph->nodeCount())->toBe(5)
        ->and($graph->getSuccessors('condition'))->toContain('branch_a')
        ->and($graph->getSuccessors('condition'))->toContain('branch_b')
        ->and($graph->inDegree['merge'])->toBe(2);
});

test('getEdgesFrom filters by source handle', function () {
    $nodes = makeNodes([
        ['id' => 'condition', 'type' => 'condition'],
        ['id' => 'true_branch'],
        ['id' => 'false_branch'],
    ]);

    $edges = makeEdges([
        ['condition', 'true_branch', 'true'],
        ['condition', 'false_branch', 'false'],
    ]);

    $graph = compiler()->compile($nodes, $edges);

    $trueEdges = $graph->getEdgesFrom('condition', 'true');
    $falseEdges = $graph->getEdgesFrom('condition', 'false');

    expect($trueEdges)->toHaveCount(1)
        ->and($trueEdges[0]['target'])->toBe('true_branch')
        ->and($falseEdges)->toHaveCount(1)
        ->and($falseEdges[0]['target'])->toBe('false_branch');
});

// ── Cycle detection ─────────────────────────────────────────

test('detects a simple cycle', function () {
    $nodes = makeNodes([
        ['id' => 'a'],
        ['id' => 'b'],
        ['id' => 'c'],
    ]);

    $edges = makeEdges([
        ['a', 'b'],
        ['b', 'c'],
        ['c', 'a'],
    ]);

    compiler()->compile($nodes, $edges);
})->throws(CycleDetectedException::class);

test('detects a self-referencing cycle', function () {
    $nodes = makeNodes([
        ['id' => 'a'],
    ]);

    $edges = makeEdges([
        ['a', 'a'],
    ]);

    compiler()->compile($nodes, $edges);
})->throws(CycleDetectedException::class);

test('cycle exception includes involved node IDs', function () {
    $nodes = makeNodes([
        ['id' => 'start'],
        ['id' => 'loop_a'],
        ['id' => 'loop_b'],
    ]);

    $edges = makeEdges([
        ['start', 'loop_a'],
        ['loop_a', 'loop_b'],
        ['loop_b', 'loop_a'],
    ]);

    try {
        compiler()->compile($nodes, $edges);
        $this->fail('Expected CycleDetectedException');
    } catch (CycleDetectedException $e) {
        expect($e->involvedNodes)->toContain('loop_a')
            ->and($e->involvedNodes)->toContain('loop_b');
    }
});

// ── Edge cases ──────────────────────────────────────────────

test('handles empty graph', function () {
    $graph = compiler()->compile([], []);

    expect($graph->nodeCount())->toBe(0)
        ->and($graph->startNodes)->toBe([]);
});

test('handles single node with no edges', function () {
    $nodes = makeNodes([
        ['id' => 'solo', 'type' => 'trigger'],
    ]);

    $graph = compiler()->compile($nodes, []);

    expect($graph->nodeCount())->toBe(1)
        ->and($graph->startNodes)->toBe(['solo'])
        ->and($graph->getSuccessors('solo'))->toBe([]);
});

test('skips edges referencing non-existent nodes', function () {
    $nodes = makeNodes([
        ['id' => 'a'],
        ['id' => 'b'],
    ]);

    $edges = makeEdges([
        ['a', 'b'],
        ['a', 'ghost_node'],
        ['ghost_source', 'b'],
    ]);

    $graph = compiler()->compile($nodes, $edges);

    expect($graph->getSuccessors('a'))->toBe(['b'])
        ->and($graph->getPredecessors('b'))->toBe(['a']);
});

test('skips nodes without an id', function () {
    $nodes = [
        ['id' => 'valid', 'type' => 'trigger'],
        ['type' => 'transform', 'name' => 'no_id_node'],
    ];

    $graph = compiler()->compile($nodes, []);

    expect($graph->nodeCount())->toBe(1);
});

// ── Expression compilation ──────────────────────────────────

test('pre-compiles expressions in node config', function () {
    $nodes = makeNodes([
        ['id' => 'http', 'type' => 'http_request', 'data' => [
            'url' => '{{ $vars.base_url }}/api/users',
            'method' => 'GET',
        ]],
    ]);

    $graph = compiler()->compile($nodes, []);

    $compiled = $graph->getCompiledConfig('http');

    expect($compiled['url']['__expr'])->toBeTrue()
        ->and($compiled['method'])->toBe('GET');
});

// ── Downstream consumers ────────────────────────────────────

test('builds downstream consumer map', function () {
    $nodes = makeNodes([
        ['id' => 'a'],
        ['id' => 'b'],
        ['id' => 'c'],
    ]);

    $edges = makeEdges([
        ['a', 'b'],
        ['a', 'c'],
    ]);

    $graph = compiler()->compile($nodes, $edges);

    expect($graph->downstreamConsumers['a'])->toContain('b')
        ->and($graph->downstreamConsumers['a'])->toContain('c')
        ->and($graph->downstreamConsumers['b'])->toBe([])
        ->and($graph->downstreamConsumers['c'])->toBe([]);
});
