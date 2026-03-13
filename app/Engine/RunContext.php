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

    /** @var list<string> Node IDs ready for execution */
    private array $readyQueue = [];

    /** @var array<string, mixed> Workspace and runtime variables */
    private array $variables;

    /** @var list<array<string, mixed>> Stack frames for loops and sub-workflows */
    private array $frameStack = [];

    private int $completedSinceFlush = 0;

    private float $lastFlushAt;

    private int $nextSequence = 1;

    private float $startedAt;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public readonly WorkflowGraph $graph,
        public readonly OutputBuffer $outputs,
        public readonly int $executionId,
        array $variables = [],
    ) {
        $this->variables = $variables;
        $this->remainingInDegree = $graph->inDegree;
        $this->lastFlushAt = microtime(true);
        $this->startedAt = microtime(true);

        // Seed the ready queue with start nodes
        foreach ($graph->startNodes as $nodeId) {
            $this->readyQueue[] = $nodeId;
        }
    }

    /**
     * Get all nodes that are ready for execution.
     *
     * @return list<string>
     */
    public function getReadyNodes(): array
    {
        return $this->readyQueue;
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
        // Remove from ready queue
        $this->readyQueue = array_values(array_filter(
            $this->readyQueue,
            fn (string $id) => $id !== $nodeId,
        ));

        // Store result
        $this->completedNodes[$nodeId] = $result;

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
                $this->readyQueue[] = $successorId;
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
        $this->readyQueue = array_values(array_filter(
            $this->readyQueue,
            fn (string $id) => $id !== $nodeId,
        ));

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

        // If single predecessor, flatten for convenience
        if (count($predecessors) === 1) {
            return $inputData[$predecessors[0]] ?? [];
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
        $nodeOutputs = [];

        foreach ($this->completedNodes as $nodeId => $result) {
            $nodeOutputs[$nodeId] = ['output' => $result->output ?? []];
        }

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
    public function shouldFlush(int $threshold = 25, float $intervalSeconds = 0.5): bool
    {
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
