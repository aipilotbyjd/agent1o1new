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

        // Small batches run inline — avoids child-process overhead
        if (count($nodeIds) <= 2) {
            return $this->runSequentialInline($nodeIds, $graph, $context);
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
     * Run multiple nodes sequentially inline (no child-process overhead).
     *
     * @param  list<string>  $nodeIds
     * @return array<string, NodeResult>
     */
    private function runSequentialInline(array $nodeIds, WorkflowGraph $graph, RunContext $context): array
    {
        $results = [];

        foreach ($nodeIds as $nodeId) {
            $payload = $this->payloadFactory->build($nodeId, $graph, $context);

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

    /**
     * Execute a chunk of nodes concurrently via Laravel Concurrency.
     *
     * Nodes are batched into $workerCount tasks so each child process
     * handles multiple nodes, reducing process-spawn overhead.
     *
     * @param  list<string>  $chunk
     * @param  array<string, NodePayload>  $payloads
     * @return array<string, NodeResult>
     */
    private function executeChunk(array $chunk, array $payloads): array
    {
        $workerCount = min($this->maxConcurrency, count($chunk));
        $batches = array_chunk($chunk, (int) ceil(count($chunk) / $workerCount));

        $tasks = [];

        foreach ($batches as $batch) {
            $serializedBatch = [];

            foreach ($batch as $nodeId) {
                $payload = $payloads[$nodeId];
                $serializedBatch[] = [
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
            }

            $tasks[] = function () use ($serializedBatch) {
                $results = [];

                foreach ($serializedBatch as $raw) {
                    $payload = new \App\Engine\Runners\NodePayload(
                        nodeId: $raw['node_id'],
                        nodeType: $raw['node_type'],
                        nodeName: $raw['node_name'],
                        config: $raw['config'],
                        inputData: $raw['input_data'],
                        credentials: $raw['credentials'],
                        variables: $raw['variables'],
                        executionMeta: $raw['execution_meta'],
                        nodeRunKey: $raw['node_run_key'],
                    );

                    $handler = \App\Engine\NodeRegistry::handler($payload->nodeType);

                    if ($handler === null) {
                        $results[$payload->nodeId] = [
                            'status' => 'failed',
                            'output' => null,
                            'error' => ['message' => "Unknown node type [{$payload->nodeType}].", 'code' => 'UNKNOWN_TYPE'],
                            'duration_ms' => 0,
                            'active_branches' => null,
                            'loop_items' => null,
                        ];

                        continue;
                    }

                    try {
                        $result = $handler->handle($payload);
                        $results[$payload->nodeId] = $result->toArray();
                    } catch (\Throwable $e) {
                        $results[$payload->nodeId] = [
                            'status' => 'failed',
                            'output' => null,
                            'error' => ['message' => $e->getMessage(), 'code' => 'NODE_EXECUTION_ERROR'],
                            'duration_ms' => 0,
                            'active_branches' => null,
                            'loop_items' => null,
                        ];
                    }
                }

                return $results;
            };
        }

        try {
            $rawBatches = Concurrency::driver('process')->run($tasks);
        } catch (\Throwable $e) {
            // If concurrency fails entirely, fall back to sequential execution
            return $this->fallbackSequential($chunk, $payloads);
        }

        $results = [];

        foreach ($rawBatches as $batchResults) {
            foreach ($batchResults as $nodeId => $rawResult) {
                $results[$nodeId] = NodeResult::fromArray($rawResult);
            }
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
