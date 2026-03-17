<?php

namespace App\Engine\Runners;

use App\Engine\Exceptions\NodeFailedException;
use App\Engine\NodeRegistry;
use App\Engine\NodeResult;
use App\Engine\RunContext;
use App\Engine\WorkflowGraph;

/**
 * Executes synchronous (non-I/O) nodes instantly within the current process.
 *
 * Resolves the appropriate handler for each node type, builds the
 * NodePayload with resolved expressions, and delegates to the handler.
 */
class SyncRunner
{
    public function __construct(private readonly NodePayloadFactory $payloadFactory) {}

    /**
     * Execute a single sync node.
     */
    public function run(string $nodeId, WorkflowGraph $graph, RunContext $context): NodeResult
    {
        $node = $graph->getNode($nodeId);
        $type = $node['type'] ?? '';

        $handler = NodeRegistry::handler($type);

        if ($handler === null) {
            return NodeResult::failed("Unknown node type [{$type}] for node [{$nodeId}].", 'UNKNOWN_TYPE');
        }

        $payload = $this->payloadFactory->build($nodeId, $graph, $context);

        try {
            return $handler->handle($payload);
        } catch (\Throwable $e) {
            $node = $graph->getNode($nodeId);

            throw new NodeFailedException(
                nodeId: $nodeId,
                nodeType: $node['type'] ?? 'unknown',
                reason: $e->getMessage(),
                errorData: ['exception' => get_class($e), 'trace' => $e->getTraceAsString()],
                previous: $e,
            );
        }
    }

    /**
     * Execute a batch of sync nodes sequentially.
     *
     * @param  list<string>  $nodeIds
     * @return array<string, NodeResult>
     */
    public function runBatch(array $nodeIds, WorkflowGraph $graph, RunContext $context): array
    {
        $results = [];

        foreach ($nodeIds as $nodeId) {
            try {
                $results[$nodeId] = $this->run($nodeId, $graph, $context);
            } catch (NodeFailedException $e) {
                $results[$nodeId] = NodeResult::failed(
                    $e->getMessage(),
                    'NODE_EXECUTION_ERROR',
                );
            }
        }

        return $results;
    }
}
