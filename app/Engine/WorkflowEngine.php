<?php

namespace App\Engine;

use App\Engine\Contracts\SuspendsExecution;
use App\Engine\Data\ExpressionParser;
use App\Engine\Data\OutputBuffer;
use App\Engine\Enums\NodeType;
use App\Engine\Exceptions\NodeFailedException;
use App\Engine\Persistence\BatchWriter;
use App\Engine\Persistence\CheckpointStore;
use App\Engine\Runners\AsyncRunner;
use App\Engine\Runners\SyncRunner;
use App\Enums\ExecutionNodeStatus;
use App\Jobs\ResumeWorkflowJob;
use App\Models\Execution;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * The core workflow execution engine.
 *
 * Runs a compiled WorkflowGraph using a frontier-based scheduler:
 *  - Sync nodes execute instantly (transforms, conditions, triggers)
 *  - Async nodes execute concurrently via Laravel Concurrency
 *  - Blocking nodes checkpoint state and requeue via delayed jobs
 *
 * Persistence is batched — node results accumulate in memory and flush
 * to the database periodically or on completion/failure.
 */
class WorkflowEngine
{
    /** @var array<int, int> executionId → last EXPIRE refresh timestamp */
    private array $lastExpireRefresh = [];

    public function __construct(
        private readonly GraphCompiler $compiler,
        private readonly ExpressionParser $expressionParser,
        private readonly SyncRunner $syncRunner,
        private readonly AsyncRunner $asyncRunner,
        private readonly BatchWriter $batchWriter,
        private readonly CheckpointStore $checkpointStore,
    ) {}

    /**
     * Execute a workflow from the beginning.
     */
    public function run(Execution $execution): void
    {
        $workflow = $execution->workflow;
        $version = $workflow->currentVersion;

        if (! $version) {
            $execution->fail(['message' => 'Workflow has no published version.']);

            return;
        }

        $graph = $this->compileGraph($version);

        if ($graph->nodeCount() === 0) {
            $execution->fail(['message' => 'Workflow has no nodes.']);

            return;
        }

        $variables = $this->loadVariables($execution);
        $variables['__trigger_data'] = $execution->trigger_data ?? [];

        $credentials = $this->loadCredentials($execution);

        $outputBuffer = new OutputBuffer(
            executionId: $execution->id,
            downstreamConsumers: $graph->downstreamConsumers,
        );

        $context = new RunContext(
            graph: $graph,
            outputs: $outputBuffer,
            executionId: $execution->id,
            variables: $variables,
            credentials: $credentials,
        );

        $execution->start();
        $this->publishSseEvent($execution->id, 'execution.started');

        $this->executeLoop($execution, $graph, $context);
    }

    /**
     * Resume a suspended execution from its checkpoint.
     */
    public function resume(Execution $execution): void
    {
        $checkpoint = $this->checkpointStore->load($execution->id);

        if (! $checkpoint) {
            $execution->fail(['message' => 'No checkpoint found for resumption.']);

            return;
        }

        $workflow = $execution->workflow;
        $version = $workflow->currentVersion;

        if (! $version) {
            $execution->fail(['message' => 'Workflow has no published version.']);

            return;
        }

        $graph = $this->compileGraph($version);
        $credentials = $this->loadCredentials($execution);

        $context = RunContext::fromCheckpoint(
            graph: $graph,
            executionId: $execution->id,
            frontierState: array_merge(
                $checkpoint->frontier_state,
                [
                    'frame_stack' => $checkpoint->frame_stack ?? [],
                    'next_sequence' => $checkpoint->next_sequence ?? 1,
                ],
            ),
            outputSnapshot: $checkpoint->output_refs ?? [],
            credentials: $credentials,
        );

        $execution->resume();
        $this->publishSseEvent($execution->id, 'execution.resumed');

        $this->checkpointStore->delete($execution->id);

        $this->executeLoop($execution, $graph, $context);
    }

    /**
     * Compile workflow version into a WorkflowGraph with caching.
     */
    private function compileGraph(\App\Models\WorkflowVersion $version): WorkflowGraph
    {
        $cacheKey = "engine:graph:{$version->id}";

        $cached = cache()->get($cacheKey);
        if ($cached instanceof WorkflowGraph) {
            return $cached;
        }

        $graph = $this->compiler->compile(
            nodes: $version->nodes ?? [],
            edges: $version->edges ?? [],
        );

        cache()->put($cacheKey, $graph, now()->addHours(6));

        return $graph;
    }

    /**
     * The main execution loop — frontier-based scheduler.
     */
    private function executeLoop(Execution $execution, WorkflowGraph $graph, RunContext $context): void
    {
        try {
            while ($context->hasReadyNodes()) {
                $readyNodes = $context->getReadyNodes();

                // Partition nodes by execution mode
                [$syncNodes, $asyncNodes, $blockingNodes] = $this->partitionNodes($readyNodes, $graph);

                // Execute sync nodes instantly
                foreach ($syncNodes as $nodeId) {
                    $this->executeNode($nodeId, $graph, $context, $execution);
                }

                // Execute async nodes concurrently via Laravel Concurrency
                if (! empty($asyncNodes)) {
                    $asyncResults = $this->asyncRunner->runBatch($asyncNodes, $graph, $context);

                    foreach ($asyncResults as $nodeId => $result) {
                        $this->commitNodeResult($nodeId, $result, $graph, $context, $execution);
                    }
                }

                // Blocking nodes — checkpoint + requeue via delayed job
                if (! empty($blockingNodes)) {
                    $suspension = $this->handleSuspension($blockingNodes[0], $graph, $context, $execution);

                    if ($suspension !== null) {
                        return;
                    }

                    // Fallback: handler doesn't implement SuspendsExecution, run inline
                    foreach ($blockingNodes as $nodeId) {
                        $this->executeNode($nodeId, $graph, $context, $execution);
                    }
                }

                // Flush to DB if threshold reached
                $this->batchWriter->flushIfNeeded($context);

                // Check for cancellation
                if ($this->isCancelled($execution->id)) {
                    $this->batchWriter->flush();
                    $execution->cancel();
                    $this->publishSseEvent($execution->id, 'execution.cancelled');

                    return;
                }
            }

            // Final flush — write any remaining node results
            $this->batchWriter->flush();

            // Determine final status
            $hasFailures = $this->hasFailedNodes($context);

            if ($hasFailures && ! $context->isFinished()) {
                $this->handleExecutionFailure($execution, $context);
            } else {
                $this->handleExecutionSuccess($execution, $context);
            }
        } catch (NodeFailedException $e) {
            $this->batchWriter->flush();
            $this->handleExecutionFailure($execution, $context, $e);
        } catch (\Throwable $e) {
            $this->batchWriter->flush();

            Log::error('Workflow engine error', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $execution->fail([
                'message' => $e->getMessage(),
                'type' => get_class($e),
            ]);

            $this->publishSseEvent($execution->id, 'execution.failed');
        } finally {
            $context->outputs->cleanup();
        }
    }

    /**
     * Execute a single node: resolve handler, run, record result, advance frontier.
     */
    private function executeNode(
        string $nodeId,
        WorkflowGraph $graph,
        RunContext $context,
        Execution $execution,
    ): void {
        $this->publishSseEvent($execution->id, 'execution.node_started', [
            'node_id' => $nodeId,
            'node_type' => $graph->getNode($nodeId)['type'] ?? 'unknown',
        ]);

        try {
            $result = $this->syncRunner->run($nodeId, $graph, $context);
        } catch (NodeFailedException $e) {
            $result = NodeResult::failed($e->getMessage(), 'NODE_EXECUTION_ERROR');
        }

        $sequence = $context->nextSequence();

        // Record for batch persistence
        $this->batchWriter->record(
            executionId: $execution->id,
            nodeId: $nodeId,
            nodeRunKey: $nodeId,
            graph: $graph,
            result: $result,
            sequence: $sequence,
        );

        // Update runtime state — advance frontier
        $context->complete(
            nodeId: $nodeId,
            result: $result,
            activeBranches: $result->activeBranches,
        );

        $this->publishSseEvent($execution->id, 'execution.node_completed', [
            'node_id' => $nodeId,
            'status' => $result->status->value,
            'duration_ms' => $result->durationMs,
            'progress' => $graph->nodeCount() > 0
                ? (int) round(($context->completedCount() / $graph->nodeCount()) * 100)
                : 100,
        ]);

        // If node failed, propagate based on workflow settings
        if ($result->status === ExecutionNodeStatus::Failed) {
            $this->handleNodeFailure($nodeId, $result, $graph, $context, $execution);
        }
    }

    /**
     * Commit a pre-computed result (from AsyncRunner) into the execution state.
     */
    private function commitNodeResult(
        string $nodeId,
        NodeResult $result,
        WorkflowGraph $graph,
        RunContext $context,
        Execution $execution,
    ): void {
        $this->publishSseEvent($execution->id, 'execution.node_started', [
            'node_id' => $nodeId,
            'node_type' => $graph->getNode($nodeId)['type'] ?? 'unknown',
        ]);

        $sequence = $context->nextSequence();

        $this->batchWriter->record(
            executionId: $execution->id,
            nodeId: $nodeId,
            nodeRunKey: $nodeId,
            graph: $graph,
            result: $result,
            sequence: $sequence,
        );

        $context->complete(
            nodeId: $nodeId,
            result: $result,
            activeBranches: $result->activeBranches,
        );

        $this->publishSseEvent($execution->id, 'execution.node_completed', [
            'node_id' => $nodeId,
            'status' => $result->status->value,
            'duration_ms' => $result->durationMs,
            'progress' => $graph->nodeCount() > 0
                ? (int) round(($context->completedCount() / $graph->nodeCount()) * 100)
                : 100,
        ]);

        if ($result->status === ExecutionNodeStatus::Failed) {
            $this->handleNodeFailure($nodeId, $result, $graph, $context, $execution);
        }
    }

    /**
     * Handle a suspendable node: checkpoint state and dispatch a delayed resume job.
     *
     * Returns the Suspension if the execution was suspended, or null if the handler
     * doesn't implement SuspendsExecution (caller should fall back to inline execution).
     */
    private function handleSuspension(
        string $nodeId,
        WorkflowGraph $graph,
        RunContext $context,
        Execution $execution,
    ): ?\App\Engine\Data\Suspension {
        $node = $graph->getNode($nodeId);
        $type = $node['type'] ?? '';
        $handler = NodeRegistry::handler($type);

        if (! $handler instanceof SuspendsExecution) {
            return null;
        }

        // Build payload and get suspension details (do NOT execute the node)
        $nodePayloadFactory = app(\App\Engine\Runners\NodePayloadFactory::class);
        $nodePayload = $nodePayloadFactory->build($nodeId, $graph, $context);
        $suspension = $handler->suspend($nodePayload);

        // Record the node as completed with the suspension output
        $result = NodeResult::completed($suspension->nodeOutput);
        $sequence = $context->nextSequence();

        $this->batchWriter->record(
            executionId: $execution->id,
            nodeId: $nodeId,
            nodeRunKey: $nodeId,
            graph: $graph,
            result: $result,
            sequence: $sequence,
        );

        // Advance frontier past the suspending node
        $context->complete(
            nodeId: $nodeId,
            result: $result,
            activeBranches: $result->activeBranches,
        );

        // Flush all pending rows before suspending
        $this->batchWriter->flush();

        // Save checkpoint
        $this->checkpointStore->save($execution, $context, $suspension);

        // Transition execution to waiting
        $execution->markWaiting($suspension->resumeAt, [
            'suspend_reason' => $suspension->reason,
            'suspended_node' => $nodeId,
        ]);

        $this->publishSseEvent($execution->id, 'execution.suspended', [
            'node_id' => $nodeId,
            'reason' => $suspension->reason,
            'resume_at' => $suspension->resumeAt->toIso8601String(),
        ]);

        // Dispatch delayed resume job
        $delaySeconds = max(0, (int) now()->diffInSeconds($suspension->resumeAt, false));

        ResumeWorkflowJob::dispatch($execution)
            ->delay(now()->addSeconds($delaySeconds));

        return $suspension;
    }

    /**
     * Partition ready nodes into sync, async, and blocking groups.
     *
     * @param  list<string>  $nodeIds
     * @return array{list<string>, list<string>, list<string>}
     */
    private function partitionNodes(array $nodeIds, WorkflowGraph $graph): array
    {
        $sync = [];
        $async = [];
        $blocking = [];

        foreach ($nodeIds as $nodeId) {
            $node = $graph->getNode($nodeId);
            $type = $node['type'] ?? '';
            $nodeType = NodeType::tryFrom($type);

            if ($nodeType === null) {
                // App nodes (google_sheets.*, slack.*, etc.) always do I/O
                if (NodeRegistry::isAppNode($type)) {
                    $async[] = $nodeId;
                } else {
                    $sync[] = $nodeId;
                }

                continue;
            }

            if ($nodeType->isSuspendable()) {
                $blocking[] = $nodeId;
            } elseif ($nodeType->isSync()) {
                $sync[] = $nodeId;
            } else {
                $async[] = $nodeId;
            }
        }

        return [$sync, $async, $blocking];
    }

    /**
     * Handle a single node failure — decide whether to continue or abort.
     */
    private function handleNodeFailure(
        string $nodeId,
        NodeResult $result,
        WorkflowGraph $graph,
        RunContext $context,
        Execution $execution,
    ): void {
        $node = $graph->getNode($nodeId);
        $continueOnFail = $node['data']['continueOnFail']
            ?? $node['config']['continueOnFail']
            ?? false;

        if (! $continueOnFail) {
            throw new NodeFailedException(
                nodeId: $nodeId,
                nodeType: $node['type'] ?? 'unknown',
                reason: $result->error['message'] ?? 'Node execution failed.',
                errorData: $result->error,
            );
        }

        // If continueOnFail is true, the frontier keeps advancing
        Log::warning("Node [{$nodeId}] failed but continueOnFail is enabled.", [
            'execution_id' => $execution->id,
            'error' => $result->error,
        ]);
    }

    /**
     * Handle successful execution completion.
     */
    private function handleExecutionSuccess(Execution $execution, RunContext $context): void
    {
        $durationMs = $context->elapsedMs();

        $execution->complete(
            resultData: ['completed_nodes' => $context->completedCount()],
            durationMs: $durationMs,
        );

        // Atomic counter update instead of COUNT(*)
        $workflow = $execution->workflow;
        $workflow->increment('execution_count');
        $workflow->update(['last_executed_at' => now()]);

        $this->publishSseEvent($execution->id, 'execution.completed', [
            'duration_ms' => $durationMs,
            'node_count' => $context->completedCount(),
        ]);
    }

    /**
     * Handle execution failure.
     */
    private function handleExecutionFailure(
        Execution $execution,
        RunContext $context,
        ?\Throwable $exception = null,
    ): void {
        $durationMs = $context->elapsedMs();

        $error = $exception
            ? ['message' => $exception->getMessage(), 'type' => get_class($exception)]
            : ['message' => 'One or more nodes failed.'];

        $execution->fail($error, $durationMs);

        // Trigger error workflow if configured
        $this->triggerErrorWorkflow($execution, $error);

        $this->publishSseEvent($execution->id, 'execution.failed', [
            'error' => $error['message'],
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Trigger the error workflow if one is configured.
     */
    private function triggerErrorWorkflow(Execution $execution, array $error): void
    {
        $workflow = $execution->workflow;

        if (! $workflow->error_workflow_id) {
            return;
        }

        try {
            $errorWorkflow = \App\Models\Workflow::find($workflow->error_workflow_id);

            if ($errorWorkflow && $errorWorkflow->is_active) {
                app(\App\Services\ExecutionService::class)->trigger(
                    workflow: $errorWorkflow,
                    user: $execution->triggeredBy ?? $workflow->creator,
                    triggerData: [
                        'source_execution_id' => $execution->id,
                        'source_workflow_id' => $workflow->id,
                        'error' => $error,
                    ],
                );
            }
        } catch (\Throwable $e) {
            Log::error('Failed to trigger error workflow.', [
                'execution_id' => $execution->id,
                'error_workflow_id' => $workflow->error_workflow_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if the execution has been cancelled via a Redis flag.
     */
    private function isCancelled(int $executionId): bool
    {
        try {
            return (bool) Redis::get("engine:cancel:{$executionId}");
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if any completed nodes have failed status.
     */
    private function hasFailedNodes(RunContext $context): bool
    {
        foreach ($context->getCompletedNodes() as $result) {
            if ($result->status === ExecutionNodeStatus::Failed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Load workspace variables for the execution.
     *
     * @return array<string, mixed>
     */
    private function loadVariables(Execution $execution): array
    {
        $workspace = $execution->workspace;
        $variables = [];

        foreach ($workspace->variables()->get() as $variable) {
            $variables[$variable->key] = $variable->is_secret
                ? decrypt($variable->value)
                : $variable->value;
        }

        return $variables;
    }

    /**
     * Load credentials for the execution and perform auto-refresh if necessary.
     *
     * @return array<string, \App\Models\Credential>
     */
    private function loadCredentials(Execution $execution): array
    {
        $execution->load('workflow.credentials');

        $credentials = [];

        foreach ($execution->workflow->credentials as $credential) {
            $nodeId = $credential->pivot->node_id;
            if ($nodeId) {
                $credentials[$nodeId] = $credential;
            }
        }

        return $credentials;
    }

    /**
     * Publish a real-time SSE event via Redis PubSub.
     *
     * @param  array<string, mixed>  $data
     */
    private function publishSseEvent(int $executionId, string $event, array $data = []): void
    {
        $payload = json_encode([
            'event' => $event,
            'execution_id' => $executionId,
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
        ]);

        try {
            $streamKey = "execution:{$executionId}:events";
            $pubsubChannel = "linkflow:execution:{$executionId}:live";
            $client = Redis::connection()->client();

            $client->pipeline(function ($pipe) use ($streamKey, $pubsubChannel, $payload) {
                $pipe->xadd($streamKey, '*', ['payload' => $payload]);
                $pipe->publish($pubsubChannel, $payload);
            });

            if (($this->lastExpireRefresh[$executionId] ?? 0) < time() - 30) {
                $client->expire($streamKey, 300);
                $this->lastExpireRefresh[$executionId] = time();
            }
        } catch (\Throwable) {
            // SSE publishing is best-effort
        }
    }
}
