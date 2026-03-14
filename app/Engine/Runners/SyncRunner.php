<?php

namespace App\Engine\Runners;

use App\Engine\Data\ExpressionParser;
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
    public function __construct(private readonly ExpressionParser $expressionParser) {}

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

        // Inject operation for app nodes (e.g., "google_sheets.append_row" → operation = "append_row")
        $operation = NodeRegistry::operation($node['type'] ?? '');
        if ($operation !== null && ! isset($resolvedConfig['operation'])) {
            $resolvedConfig['operation'] = $operation;
        }

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
}
