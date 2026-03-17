<?php

namespace App\Engine\Runners;

use App\Engine\NodeRegistry;
use App\Engine\NodeResult;
use App\Engine\RunContext;
use App\Engine\WorkflowGraph;
use Illuminate\Support\Facades\Concurrency;

/**
 * Executes async (I/O-bound) nodes concurrently using Laravel's process concurrency.
 *
 * Builds immutable payloads from the current RunContext, then dispatches
 * handlers in parallel child processes. Results are returned as an array
 * for sequential commitment by the engine.
 */
class AsyncRunner
{
    private int $maxConcurrency;

    public function __construct(
        private readonly NodePayloadFactory $payloadFactory,
        ?int $maxConcurrency = null,
    ) {
        $this->maxConcurrency = $maxConcurrency ?? (int) config('workflow.async_max_concurrency', 4);
    }

    /**
     * Run a batch of async nodes concurrently.
     *
     * @param  list<string>  $nodeIds
     * @return array<string, NodeResult> nodeId => result
     */
    public function runBatch(array $nodeIds, WorkflowGraph $graph, RunContext $context): array
    {
        if (empty($nodeIds)) {
            return [];
        }

        // If only one node, run inline — no overhead from concurrency
        if (count($nodeIds) === 1) {
            return $this->runSingle($nodeIds[0], $graph, $context);
        }

        // Build all payloads BEFORE launching concurrent work (reads from mutable RunContext)
        $payloads = [];
        foreach ($nodeIds as $nodeId) {
            $payloads[$nodeId] = $this->payloadFactory->build($nodeId, $graph, $context);
        }

        // Chunk by max concurrency
        $chunks = array_chunk($nodeIds, $this->maxConcurrency, true);
        $allResults = [];

        foreach ($chunks as $chunk) {
            $chunkResults = $this->executeChunk(array_values($chunk), $payloads);
            $allResults = array_merge($allResults, $chunkResults);
        }

        return $allResults;
    }

    /**
     * Execute a single node inline (no process overhead).
     *
     * @return array<string, NodeResult>
     */
    private function runSingle(string $nodeId, WorkflowGraph $graph, RunContext $context): array
    {
        $payload = $this->payloadFactory->build($nodeId, $graph, $context);

        try {
            $handler = NodeRegistry::handler($payload->nodeType);

            if ($handler === null) {
                return [$nodeId => NodeResult::failed("Unknown node type [{$payload->nodeType}].", 'UNKNOWN_TYPE')];
            }

            return [$nodeId => $handler->handle($payload)];
        } catch (\Throwable $e) {
            return [$nodeId => NodeResult::failed($e->getMessage(), 'NODE_EXECUTION_ERROR')];
        }
    }

    /**
     * Execute a chunk of nodes concurrently via Laravel Concurrency.
     *
     * @param  list<string>  $chunk
     * @param  array<string, NodePayload>  $payloads
     * @return array<string, NodeResult>
     */
    private function executeChunk(array $chunk, array $payloads): array
    {
        $tasks = [];
        $indexToNodeId = [];

        foreach ($chunk as $index => $nodeId) {
            $payload = $payloads[$nodeId];
            $indexToNodeId[$index] = $nodeId;

            // Serialize payload to plain array for the child process
            $serializedPayload = [
                'node_id' => $payload->nodeId,
                'node_type' => $payload->nodeType,
                'node_name' => $payload->nodeName,
                'config' => $payload->config,
                'input_data' => $payload->inputData,
                'credentials' => $payload->credentials,
                'variables' => $payload->variables,
                'execution_meta' => $payload->executionMeta,
                'node_run_key' => $payload->nodeRunKey,
            ];

            $tasks[] = function () use ($serializedPayload) {
                // Reconstruct payload inside child process
                $payload = new \App\Engine\Runners\NodePayload(
                    nodeId: $serializedPayload['node_id'],
                    nodeType: $serializedPayload['node_type'],
                    nodeName: $serializedPayload['node_name'],
                    config: $serializedPayload['config'],
                    inputData: $serializedPayload['input_data'],
                    credentials: $serializedPayload['credentials'],
                    variables: $serializedPayload['variables'],
                    executionMeta: $serializedPayload['execution_meta'],
                    nodeRunKey: $serializedPayload['node_run_key'],
                );

                $handler = \App\Engine\NodeRegistry::handler($payload->nodeType);

                if ($handler === null) {
                    return [
                        'status' => 'failed',
                        'output' => null,
                        'error' => ['message' => "Unknown node type [{$payload->nodeType}].", 'code' => 'UNKNOWN_TYPE'],
                        'duration_ms' => 0,
                        'active_branches' => null,
                        'loop_items' => null,
                    ];
                }

                try {
                    $result = $handler->handle($payload);

                    return $result->toArray();
                } catch (\Throwable $e) {
                    return [
                        'status' => 'failed',
                        'output' => null,
                        'error' => ['message' => $e->getMessage(), 'code' => 'NODE_EXECUTION_ERROR'],
                        'duration_ms' => 0,
                        'active_branches' => null,
                        'loop_items' => null,
                    ];
                }
            };
        }

        try {
            $rawResults = Concurrency::driver('process')->run($tasks);
        } catch (\Throwable $e) {
            // If concurrency fails entirely, fall back to sequential execution
            return $this->fallbackSequential($chunk, $payloads);
        }

        $results = [];

        foreach ($rawResults as $index => $rawResult) {
            $nodeId = $indexToNodeId[$index];
            $results[$nodeId] = NodeResult::fromArray($rawResult);
        }

        return $results;
    }

    /**
     * Fallback: run nodes sequentially if concurrency driver fails.
     *
     * @param  list<string>  $chunk
     * @param  array<string, NodePayload>  $payloads
     * @return array<string, NodeResult>
     */
    private function fallbackSequential(array $chunk, array $payloads): array
    {
        $results = [];

        foreach ($chunk as $nodeId) {
            $payload = $payloads[$nodeId];

            try {
                $handler = NodeRegistry::handler($payload->nodeType);

                if ($handler === null) {
                    $results[$nodeId] = NodeResult::failed("Unknown node type [{$payload->nodeType}].", 'UNKNOWN_TYPE');

                    continue;
                }

                $results[$nodeId] = $handler->handle($payload);
            } catch (\Throwable $e) {
                $results[$nodeId] = NodeResult::failed($e->getMessage(), 'NODE_EXECUTION_ERROR');
            }
        }

        return $results;
    }
}
