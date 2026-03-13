<?php

namespace App\Engine;

use App\Engine\Data\ExpressionParser;
use App\Engine\Exceptions\CycleDetectedException;

/**
 * Compiles WorkflowVersion JSON (nodes + edges) into an immutable WorkflowGraph.
 *
 * Performs:
 *  1. Node indexing (O(1) lookup by ID)
 *  2. Adjacency list construction (successors + predecessors)
 *  3. In-degree computation
 *  4. Cycle detection via Kahn's algorithm
 *  5. Start node identification
 *  6. Expression template pre-compilation
 *  7. Output dependency mapping (for memory optimisation)
 */
class GraphCompiler
{
    public function __construct(private readonly ExpressionParser $expressionParser) {}

    /**
     * Compile raw nodes and edges arrays into a WorkflowGraph.
     *
     * @param  list<array<string, mixed>>  $nodes  Node definitions from WorkflowVersion->nodes
     * @param  list<array<string, mixed>>  $edges  Edge definitions from WorkflowVersion->edges
     *
     * @throws CycleDetectedException
     */
    public function compile(array $nodes, array $edges): WorkflowGraph
    {
        $nodeMap = $this->buildNodeMap($nodes);
        [$successors, $predecessors, $edgeMap] = $this->buildAdjacencyLists($nodeMap, $edges);
        $inDegree = $this->computeInDegree($nodeMap, $predecessors);

        $this->detectCycles($nodeMap, $successors, $inDegree);

        $startNodes = $this->findStartNodes($inDegree);
        $compiledExpressions = $this->compileExpressions($nodeMap);
        $downstreamConsumers = $this->buildDownstreamConsumers($nodeMap, $successors);

        return new WorkflowGraph(
            nodeMap: $nodeMap,
            successors: $successors,
            predecessors: $predecessors,
            inDegree: $inDegree,
            startNodes: $startNodes,
            compiledExpressions: $compiledExpressions,
            downstreamConsumers: $downstreamConsumers,
            edgeMap: $edgeMap,
        );
    }

    /**
     * Index nodes by their ID for O(1) lookup.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, array<string, mixed>>
     */
    private function buildNodeMap(array $nodes): array
    {
        $map = [];

        foreach ($nodes as $node) {
            $id = $node['id'] ?? null;

            if ($id === null) {
                continue;
            }

            $map[$id] = $node;
        }

        return $map;
    }

    /**
     * Build adjacency lists from edges.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  list<array<string, mixed>>  $edges
     * @return array{array<string, list<string>>, array<string, list<string>>, array<string, list<array<string, string>>>}
     */
    private function buildAdjacencyLists(array $nodeMap, array $edges): array
    {
        $successors = [];
        $predecessors = [];
        $edgeMap = [];

        // Initialise empty lists for all nodes
        foreach ($nodeMap as $id => $node) {
            $successors[$id] = [];
            $predecessors[$id] = [];
            $edgeMap[$id] = [];
        }

        foreach ($edges as $edge) {
            $source = $edge['source'] ?? null;
            $target = $edge['target'] ?? null;

            if ($source === null || $target === null) {
                continue;
            }

            // Skip edges referencing non-existent nodes
            if (! isset($nodeMap[$source]) || ! isset($nodeMap[$target])) {
                continue;
            }

            $successors[$source][] = $target;
            $predecessors[$target][] = $source;

            $edgeMap[$source][] = [
                'target' => $target,
                'sourceHandle' => $edge['sourceHandle'] ?? 'output',
                'targetHandle' => $edge['targetHandle'] ?? 'input',
            ];
        }

        // Deduplicate
        foreach ($successors as $id => $list) {
            $successors[$id] = array_values(array_unique($list));
        }

        foreach ($predecessors as $id => $list) {
            $predecessors[$id] = array_values(array_unique($list));
        }

        return [$successors, $predecessors, $edgeMap];
    }

    /**
     * Compute in-degree (number of predecessors) for each node.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  array<string, list<string>>  $predecessors
     * @return array<string, int>
     */
    private function computeInDegree(array $nodeMap, array $predecessors): array
    {
        $inDegree = [];

        foreach ($nodeMap as $id => $node) {
            $inDegree[$id] = count($predecessors[$id] ?? []);
        }

        return $inDegree;
    }

    /**
     * Detect cycles using Kahn's algorithm.
     *
     * If the number of nodes processed in topological order is less than
     * the total node count, a cycle exists.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  array<string, list<string>>  $successors
     * @param  array<string, int>  $inDegree
     *
     * @throws CycleDetectedException
     */
    private function detectCycles(array $nodeMap, array $successors, array $inDegree): void
    {
        $remaining = $inDegree;
        $queue = [];

        // Seed with zero-inDegree nodes
        foreach ($remaining as $id => $degree) {
            if ($degree === 0) {
                $queue[] = $id;
            }
        }

        $processed = 0;

        while (! empty($queue)) {
            $current = array_shift($queue);
            $processed++;

            foreach ($successors[$current] ?? [] as $successor) {
                $remaining[$successor]--;

                if ($remaining[$successor] === 0) {
                    $queue[] = $successor;
                }
            }
        }

        if ($processed < count($nodeMap)) {
            // Identify nodes involved in the cycle
            $cycleNodes = [];
            foreach ($remaining as $id => $degree) {
                if ($degree > 0) {
                    $cycleNodes[] = $id;
                }
            }

            throw new CycleDetectedException($cycleNodes);
        }
    }

    /**
     * Find all start nodes (nodes with no predecessors).
     *
     * @param  array<string, int>  $inDegree
     * @return list<string>
     */
    private function findStartNodes(array $inDegree): array
    {
        $startNodes = [];

        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $startNodes[] = $id;
            }
        }

        return $startNodes;
    }

    /**
     * Pre-compile expression templates in all node configs.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @return array<string, array<string, mixed>>
     */
    private function compileExpressions(array $nodeMap): array
    {
        $compiled = [];

        foreach ($nodeMap as $id => $node) {
            $config = $node['data'] ?? $node['config'] ?? [];

            if (! is_array($config) || empty($config)) {
                $compiled[$id] = [];

                continue;
            }

            $compiled[$id] = $this->expressionParser->compileConfig($config);
        }

        return $compiled;
    }

    /**
     * Build a map of which downstream nodes consume each node's output.
     * Used by OutputBuffer for ref-count eviction.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  array<string, list<string>>  $successors
     * @return array<string, list<string>>
     */
    private function buildDownstreamConsumers(array $nodeMap, array $successors): array
    {
        $consumers = [];

        foreach ($nodeMap as $id => $node) {
            $consumers[$id] = $successors[$id];
        }

        return $consumers;
    }
}
