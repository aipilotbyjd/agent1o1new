<?php

namespace App\Engine;

use App\Engine\Data\OutputBuffer;

/**
 * Mutable runtime state for a single workflow execution.
 *
 * Manages the frontier (ready queue), tracks completed nodes,
 * decrements in-degree counters as predecessors finish, and
 * coordinates flush/suspend decisions.
 */
class RunContext
{
    /** @var array<string, int> nodeId → remaining predecessor count */
    private array $remainingInDegree;

    /** @var array<string, NodeResult> nodeId → result */
    private array $completedNodes = [];

    /** @var array<string, true> Node IDs ready for execution */
    private array $readyQueue = [];

    /** @var array<string, mixed> Workspace and runtime variables */
    private array $variables;

    /** @var list<array<string, mixed>> Stack frames for loops and sub-workflows */
    private array $frameStack = [];

    /** @var array<string, array{output: array<string, mixed>}> Cached node outputs for expression context */
    private array $expressionNodeOutputs = [];

    private int $completedSinceFlush = 0;

    private float $lastFlushAt;

    private int $nextSequence = 1;

    private float $startedAt;

    /** @var array<string, \App\Models\Credential> nodeId → Credential instance */
    private array $credentials;

    /** @var array<int, true> Credential IDs that have already been refreshed */
    private array $refreshedCredentialIds = [];

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, \App\Models\Credential>  $credentials
     */
    public function __construct(
        public readonly WorkflowGraph $graph,
        public readonly OutputBuffer $outputs,
        public readonly int $executionId,
        array $variables = [],
        array $credentials = [],
    ) {
        $this->variables = $variables;
        $this->credentials = $credentials;
        $this->remainingInDegree = $graph->inDegree;
        $this->lastFlushAt = microtime(true);
        $this->startedAt = microtime(true);

        // Seed the ready queue with start nodes
        foreach ($graph->startNodes as $nodeId) {
            $this->readyQueue[$nodeId] = true;
        }
    }

    /**
     * Get a credential assigned to a node.
     */
    public function getCredential(string $nodeId): ?\App\Models\Credential
    {
        $credential = $this->credentials[$nodeId] ?? null;

        if ($credential === null) {
            return null;
        }

        if ($this->shouldRefreshCredential($credential)) {
            $credential = $this->refreshCredential($credential);
            $this->credentials[$nodeId] = $credential;
        }

        return $credential;
    }

    private function shouldRefreshCredential(\App\Models\Credential $credential): bool
    {
        if (isset($this->refreshedCredentialIds[$credential->id])) {
            return false;
        }

        return $credential->expires_at
            && $credential->expires_at->copy()->subMinutes(5)->isPast();
    }

    private function refreshCredential(\App\Models\Credential $credential): \App\Models\Credential
    {
        $this->refreshedCredentialIds[$credential->id] = true;

        try {
            $oauthService = app(\App\Services\OAuthCredentialFlowService::class);
            $refreshed = $oauthService->refreshToken($credential);

            if ($refreshed) {
                return $refreshed;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                "Could not refresh token for credential {$credential->id}",
                ['error' => $e->getMessage()],
            );
        }

        return $credential;
    }

    /**
     * Get all nodes that are ready for execution.
     *
     * @return list<string>
     */
    public function getReadyNodes(): array
    {
        return array_keys($this->readyQueue);
    }

    /**
     * Check if there are nodes ready to execute.
     */
    public function hasReadyNodes(): bool
    {
        return ! empty($this->readyQueue);
    }

    /**
     * Check if all nodes have completed (execution is done).
     */
    public function isFinished(): bool
    {
        return ! $this->hasReadyNodes()
            && count($this->completedNodes) === $this->graph->nodeCount();
    }

    /**
     * Mark a node as completed and advance the frontier.
     *
     * Decrements in-degree of all successors. When a successor's in-degree
     * reaches zero, it joins the ready queue.
     *
     * @param  list<string>|null  $activeBranches  For conditional nodes: only activate edges on these handles.
     */
    public function complete(string $nodeId, NodeResult $result, ?array $activeBranches = null): void
    {
        // Remove from ready queue — was O(n), now O(1)
        unset($this->readyQueue[$nodeId]);

        // Store result
        $this->completedNodes[$nodeId] = $result;
        $this->expressionNodeOutputs[$nodeId] = ['output' => $result->output ?? []];

        // Store output in buffer
        $this->outputs->store($nodeId, $result->output);

        $this->completedSinceFlush++;

        // Determine which successors to advance
        $successorsToAdvance = $this->resolveSuccessors($nodeId, $activeBranches);

        // Decrement in-degree of successors
        foreach ($successorsToAdvance as $successorId) {
            if (! isset($this->remainingInDegree[$successorId])) {
                continue;
            }

            $this->remainingInDegree[$successorId]--;

            if ($this->remainingInDegree[$successorId] <= 0) {
                $this->readyQueue[$successorId] = true;
            }
        }

        // Release output ref for consumed predecessors
        foreach ($this->graph->getPredecessors($nodeId) as $predecessorId) {
            $this->outputs->release($predecessorId);
        }
    }

    /**
     * Mark a node as skipped (branch not taken).
     *
     * Propagates the skip to all downstream nodes that depend solely on this branch.
     */
    public function skip(string $nodeId): void
    {
        unset($this->readyQueue[$nodeId]);

        $this->completedNodes[$nodeId] = NodeResult::skipped();
        $this->completedSinceFlush++;
    }

    /**
     * Get the result of a completed node.
     */
    public function getResult(string $nodeId): ?NodeResult
    {
        return $this->completedNodes[$nodeId] ?? null;
    }

    /**
     * Get the output data from a completed node.
     *
     * @return array<string, mixed>|null
     */
    public function getNodeOutput(string $nodeId): ?array
    {
        return $this->outputs->get($nodeId);
    }

    /**
     * Gather input data for a node from all its predecessors' outputs.
     *
     * @return array<string, mixed>
     */
    public function gatherInputData(string $nodeId): array
    {
        $predecessors = $this->graph->getPredecessors($nodeId);
        $inputData = [];

        foreach ($predecessors as $predecessorId) {
            $output = $this->outputs->get($predecessorId);

            if ($output !== null) {
                $inputData[$predecessorId] = $output;
            }
        }

        // For single predecessor, flatten at top level for convenience
        // but also include the namespaced key so "nodeId.field" paths work.
        if (count($predecessors) === 1) {
            $flat = $inputData[$predecessors[0]] ?? [];

            return array_merge($flat, $inputData);
        }

        return $inputData;
    }

    /**
     * Build the expression context for resolving templates.
     *
     * @return array<string, mixed>
     */
    public function buildExpressionContext(): array
    {
        $nodeOutputs = $this->expressionNodeOutputs;

        $currentFrame = end($this->frameStack) ?: [];

        return [
            'nodes' => $nodeOutputs,
            'trigger' => $this->variables['__trigger_data'] ?? [],
            'vars' => $this->variables,
            'env' => [
                'app_env' => config('app.env'),
                'app_url' => config('app.url'),
            ],
            'execution' => [
                'id' => $this->executionId,
            ],
            'loop' => $currentFrame['loop'] ?? [],
        ];
    }

    /**
     * Get and increment the next sequence number.
     */
    public function nextSequence(): int
    {
        return $this->nextSequence++;
    }

    /**
     * Whether the BatchWriter should flush accumulated rows.
     */
    public function shouldFlush(): bool
    {
        $threshold = (int) config('workflow.batch_flush_threshold', 100);
        $intervalSeconds = (float) config('workflow.batch_flush_interval', 1.0);

        if ($this->completedSinceFlush >= $threshold) {
            return true;
        }

        if ((microtime(true) - $this->lastFlushAt) >= $intervalSeconds) {
            return true;
        }

        return false;
    }

    /**
     * Reset flush counters after a successful flush.
     */
    public function markFlushed(): void
    {
        $this->completedSinceFlush = 0;
        $this->lastFlushAt = microtime(true);
    }

    /**
     * Get elapsed wall-clock time since execution started (in milliseconds).
     */
    public function elapsedMs(): int
    {
        return (int) ((microtime(true) - $this->startedAt) * 1000);
    }

    /**
     * Get the count of completed nodes.
     */
    public function completedCount(): int
    {
        return count($this->completedNodes);
    }

    /**
     * Get the total count of completed nodes since last flush (for batch writer).
     */
    public function pendingFlushCount(): int
    {
        return $this->completedSinceFlush;
    }

    /**
     * Set a runtime variable.
     */
    public function setVariable(string $key, mixed $value): void
    {
        $this->variables[$key] = $value;
    }

    /**
     * Get all variables.
     *
     * @return array<string, mixed>
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * Push a frame onto the stack (for loops/sub-workflows).
     *
     * @param  array<string, mixed>  $frame
     */
    public function pushFrame(array $frame): void
    {
        $this->frameStack[] = $frame;
    }

    /**
     * Pop the top frame from the stack.
     *
     * @return array<string, mixed>|null
     */
    public function popFrame(): ?array
    {
        return array_pop($this->frameStack) ?: null;
    }

    /**
     * Get all completed node results (for batch persistence).
     *
     * @return array<string, NodeResult>
     */
    public function getCompletedNodes(): array
    {
        return $this->completedNodes;
    }

    /**
     * Serialize the full runtime state for checkpointing.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $completedSerialized = [];
        foreach ($this->completedNodes as $nodeId => $result) {
            $completedSerialized[$nodeId] = $result->toArray();
        }

        return [
            'ready_queue' => array_keys($this->readyQueue),
            'remaining_in_degree' => $this->remainingInDegree,
            'completed_nodes' => $completedSerialized,
            'variables' => $this->variables,
            'next_sequence' => $this->nextSequence,
            'frame_stack' => $this->frameStack,
        ];
    }

    /**
     * Restore RunContext from a checkpoint snapshot.
     *
     * @param  array<string, mixed>  $frontierState
     * @param  array<string, mixed>  $outputSnapshot
     * @param  array<string, \App\Models\Credential>  $credentials
     */
    public static function fromCheckpoint(
        WorkflowGraph $graph,
        int $executionId,
        array $frontierState,
        array $outputSnapshot,
        array $credentials = [],
    ): self {
        $instance = new self(
            graph: $graph,
            outputs: OutputBuffer::fromSnapshot($executionId, $outputSnapshot, $graph->downstreamConsumers),
            executionId: $executionId,
            variables: $frontierState['variables'] ?? [],
            credentials: $credentials,
        );

        // Restore internal state
        $instance->readyQueue = array_fill_keys($frontierState['ready_queue'] ?? [], true);
        $instance->remainingInDegree = $frontierState['remaining_in_degree'] ?? $graph->inDegree;
        $instance->nextSequence = $frontierState['next_sequence'] ?? 1;
        $instance->frameStack = $frontierState['frame_stack'] ?? [];

        // Restore completed nodes from serialized NodeResults
        foreach ($frontierState['completed_nodes'] ?? [] as $nodeId => $resultData) {
            $instance->completedNodes[$nodeId] = NodeResult::fromArray($resultData);
        }

        foreach ($instance->completedNodes as $nodeId => $result) {
            $instance->expressionNodeOutputs[$nodeId] = ['output' => $result->output ?? []];
        }

        return $instance;
    }

    /**
     * Determine which successors to advance for a given node.
     *
     * For conditional nodes, only edges matching the active branches are followed.
     * For all other nodes, all successors are advanced.
     *
     * @param  list<string>|null  $activeBranches
     * @return list<string>
     */
    private function resolveSuccessors(string $nodeId, ?array $activeBranches): array
    {
        if ($activeBranches === null) {
            return $this->graph->getSuccessors($nodeId);
        }

        // Only follow edges whose sourceHandle matches an active branch
        $activeSuccessors = [];

        foreach ($activeBranches as $branch) {
            $edges = $this->graph->getEdgesFrom($nodeId, $branch);

            foreach ($edges as $edge) {
                $activeSuccessors[] = $edge['target'];
            }
        }

        return array_values(array_unique($activeSuccessors));
    }
}
