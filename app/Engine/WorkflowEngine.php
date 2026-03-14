<?php

namespace App\Engine;

use App\Engine\Data\ExpressionParser;
use App\Engine\Data\OutputBuffer;
use App\Engine\Enums\NodeType;
use App\Engine\Exceptions\NodeFailedException;
use App\Engine\Persistence\BatchWriter;
use App\Engine\Runners\SyncRunner;
use App\Enums\ExecutionNodeStatus;
use App\Models\Execution;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * The core workflow execution engine.
 *
 * Runs a compiled WorkflowGraph using a frontier-based scheduler:
 *  - Sync nodes execute instantly (transforms, conditions, triggers)
 *  - Async nodes will execute concurrently via Amp (Phase 2)
 *  - Blocking nodes trigger checkpoint + requeue (Phase 5)
 *
 * Persistence is batched — node results accumulate in memory and flush
 * to the database periodically or on completion/failure.
 */
class WorkflowEngine
{
    public function __construct(
        private readonly GraphCompiler $compiler,
        private readonly ExpressionParser $expressionParser,
        private readonly SyncRunner $syncRunner,
        private readonly BatchWriter $batchWriter,
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

                // Async nodes (Phase 2 — for now execute sequentially via SyncRunner)
                foreach ($asyncNodes as $nodeId) {
                    $this->executeNode($nodeId, $graph, $context, $execution);
                }

                // Blocking nodes (Phase 5 — checkpoint + requeue)
                // For now, execute inline
                foreach ($blockingNodes as $nodeId) {
                    $this->executeNode($nodeId, $graph, $context, $execution);
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
        $oauthService = app(\App\Services\OAuthCredentialFlowService::class);

        foreach ($execution->workflow->credentials as $credential) {
            if ($credential->expires_at && $credential->expires_at->copy()->subMinutes(5)->isPast()) {
                try {
                    $refreshed = $oauthService->refreshToken($credential);
                    if ($refreshed) {
                        $credential = $refreshed;
                    }
                } catch (\Throwable $e) {
                    // Log the error but proceed with the old credential (could fail later in node execution)
                    Log::warning("Could not refresh token for credential {$credential->id}", ['error' => $e->getMessage()]);
                }
            }

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

            Redis::connection()->client()->xadd($streamKey, '*', ['payload' => $payload]);
            Redis::connection()->client()->expire($streamKey, 300);
            Redis::connection()->client()->publish($pubsubChannel, $payload);
        } catch (\Throwable) {
            // SSE publishing is best-effort
        }
    }
}
