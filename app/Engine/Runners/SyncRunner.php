<?php

namespace App\Engine\Runners;

use App\Engine\Contracts\NodeHandler;
use App\Engine\Data\ExpressionParser;
use App\Engine\Enums\NodeType;
use App\Engine\Exceptions\NodeFailedException;
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
    public function __construct(private readonly ExpressionParser $expressionParser) {}

    /**
     * Execute a single sync node.
     */
    public function run(string $nodeId, WorkflowGraph $graph, RunContext $context): NodeResult
    {
        $nodeType = $graph->getNodeType($nodeId);

        if ($nodeType === null) {
            return NodeResult::failed("Unknown node type for node [{$nodeId}].", 'UNKNOWN_TYPE');
        }

        $handler = $this->resolveHandler($nodeType);
        $payload = $this->buildPayload($nodeId, $graph, $context);

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

    /**
     * Build the NodePayload for a node with fully resolved expressions.
     */
    private function buildPayload(string $nodeId, WorkflowGraph $graph, RunContext $context): NodePayload
    {
        $node = $graph->getNode($nodeId);
        $compiledConfig = $graph->getCompiledConfig($nodeId);
        $expressionContext = $context->buildExpressionContext();

        // Resolve compiled expressions in config
        $resolvedConfig = ! empty($compiledConfig)
            ? $this->expressionParser->resolveConfig($compiledConfig, $expressionContext)
            : ($node['data'] ?? $node['config'] ?? []);

        // Gather input data from predecessors
        $inputData = $context->gatherInputData($nodeId);

        return new NodePayload(
            nodeId: $nodeId,
            nodeType: $node['type'] ?? 'unknown',
            nodeName: $node['name'] ?? $node['data']['name'] ?? $nodeId,
            config: $resolvedConfig,
            inputData: $inputData,
            credentials: null,
            variables: $context->getVariables(),
            executionMeta: [
                'execution_id' => $context->executionId,
                'trigger_data' => $context->getVariables()['__trigger_data'] ?? [],
            ],
            nodeRunKey: $nodeId,
        );
    }

    private function resolveHandler(NodeType $nodeType): NodeHandler
    {
        $handlerClass = $nodeType->handlerClass();

        return app($handlerClass);
    }
}
