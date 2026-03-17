<?php

namespace App\Engine\Runners;

use App\Engine\Data\ExpressionParser;
use App\Engine\NodeRegistry;
use App\Engine\RunContext;
use App\Engine\WorkflowGraph;

/**
 * Builds immutable NodePayload instances from the current RunContext.
 *
 * Extracted so both SyncRunner and AsyncRunner share the same
 * payload-building logic without duplicating code.
 */
class NodePayloadFactory
{
    public function __construct(private readonly ExpressionParser $expressionParser) {}

    /**
     * Build a NodePayload for a single node with fully resolved expressions.
     */
    public function build(string $nodeId, WorkflowGraph $graph, RunContext $context): NodePayload
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

        $credentialData = $context->getCredential($nodeId)?->data;
        if (is_string($credentialData)) {
            $credentialData = json_decode($credentialData, true);
        }

        return new NodePayload(
            nodeId: $nodeId,
            nodeType: $node['type'] ?? 'unknown',
            nodeName: $node['name'] ?? $node['data']['name'] ?? $nodeId,
            config: $resolvedConfig,
            inputData: $inputData,
            credentials: $credentialData,
            variables: $context->getVariables(),
            executionMeta: [
                'execution_id' => $context->executionId,
                'trigger_data' => $context->getVariables()['__trigger_data'] ?? [],
            ],
            nodeRunKey: $nodeId,
        );
    }
}
