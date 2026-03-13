<?php

namespace App\Engine;

use App\Engine\Enums\NodeType;

/**
 * Immutable compiled representation of a workflow version.
 *
 * Produced by GraphCompiler and cached per workflow_version_id.
 * Contains pre-built adjacency lists, pre-computed in-degree counts,
 * pre-parsed expression templates, and output dependency maps.
 */
class WorkflowGraph
{
    /**
     * @param  array<string, array<string, mixed>>  $nodeMap  nodeId → full node definition
     * @param  array<string, list<string>>  $successors  nodeId → list of successor node IDs
     * @param  array<string, list<string>>  $predecessors  nodeId → list of predecessor node IDs
     * @param  array<string, int>  $inDegree  nodeId → number of predecessors
     * @param  list<string>  $startNodes  Nodes with inDegree === 0
     * @param  array<string, array<string, mixed>>  $compiledExpressions  nodeId → compiled config with expression tokens
     * @param  array<string, list<string>>  $downstreamConsumers  nodeId → list of downstream nodes that reference this output
     * @param  array<string, list<array<string, string>>>  $edgeMap  sourceId → list of edge definitions (with target, sourceHandle, targetHandle)
     */
    public function __construct(
        public readonly array $nodeMap,
        public readonly array $successors,
        public readonly array $predecessors,
        public readonly array $inDegree,
        public readonly array $startNodes,
        public readonly array $compiledExpressions,
        public readonly array $downstreamConsumers,
        public readonly array $edgeMap,
    ) {}

    /**
     * Get a node definition by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getNode(string $nodeId): ?array
    {
        return $this->nodeMap[$nodeId] ?? null;
    }

    /**
     * Get the NodeType enum for a node.
     */
    public function getNodeType(string $nodeId): ?NodeType
    {
        $node = $this->getNode($nodeId);

        if ($node === null) {
            return null;
        }

        return NodeType::resolve($node['type'] ?? '');
    }

    /**
     * Get successor node IDs for a given node.
     *
     * @return list<string>
     */
    public function getSuccessors(string $nodeId): array
    {
        return $this->successors[$nodeId] ?? [];
    }

    /**
     * Get predecessor node IDs for a given node.
     *
     * @return list<string>
     */
    public function getPredecessors(string $nodeId): array
    {
        return $this->predecessors[$nodeId] ?? [];
    }

    /**
     * Get the pre-compiled expression config for a node.
     *
     * @return array<string, mixed>
     */
    public function getCompiledConfig(string $nodeId): array
    {
        return $this->compiledExpressions[$nodeId] ?? [];
    }

    /**
     * Get edges originating from a node, optionally filtered by source handle.
     *
     * @return list<array<string, string>>
     */
    public function getEdgesFrom(string $nodeId, ?string $sourceHandle = null): array
    {
        $edges = $this->edgeMap[$nodeId] ?? [];

        if ($sourceHandle === null) {
            return $edges;
        }

        return array_values(array_filter(
            $edges,
            fn (array $edge) => ($edge['sourceHandle'] ?? 'output') === $sourceHandle,
        ));
    }

    /**
     * Get total number of nodes in the graph.
     */
    public function nodeCount(): int
    {
        return count($this->nodeMap);
    }
}
