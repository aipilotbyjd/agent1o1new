<?php

namespace App\Engine\Persistence;

use App\Engine\NodeResult;
use App\Engine\RunContext;
use App\Engine\WorkflowGraph;
use Illuminate\Support\Facades\DB;

/**
 * Accumulates ExecutionNode rows in memory and flushes them
 * in bulk via a single DB::upsert() call.
 *
 * Flush triggers:
 *  - completedSinceFlush >= threshold (default 25)
 *  - Time since last flush >= interval (default 500ms)
 *  - Explicitly on suspend, failure, or completion
 */
class BatchWriter
{
    /** @var list<array<string, mixed>> Rows waiting to be flushed */
    private array $pendingRows = [];

    /**
     * Queue a completed node for persistence.
     */
    public function record(
        int $executionId,
        string $nodeId,
        string $nodeRunKey,
        WorkflowGraph $graph,
        NodeResult $result,
        int $sequence,
        ?int $loopIndex = null,
        ?string $parentFrame = null,
    ): void {
        $node = $graph->getNode($nodeId);

        $this->pendingRows[] = [
            'execution_id' => $executionId,
            'node_id' => $nodeId,
            'node_run_key' => $nodeRunKey,
            'node_type' => $node['type'] ?? 'unknown',
            'node_name' => $node['name'] ?? $node['data']['name'] ?? $nodeId,
            'status' => $result->status->value,
            'started_at' => now()->subMilliseconds($result->durationMs ?? 0),
            'finished_at' => now(),
            'duration_ms' => $result->durationMs,
            'input_data' => null,
            'output_data' => $result->output ? json_encode($result->output) : null,
            'error' => $result->error ? json_encode($result->error) : null,
            'sequence' => $sequence,
            'loop_index' => $loopIndex,
            'parent_frame' => $parentFrame,
        ];
    }

    /**
     * Flush all pending rows to the database in a single upsert.
     *
     * @return int Number of rows flushed.
     */
    public function flush(): int
    {
        if (empty($this->pendingRows)) {
            return 0;
        }

        $rows = $this->pendingRows;
        $this->pendingRows = [];

        DB::table('execution_nodes')->upsert(
            $rows,
            ['execution_id', 'node_run_key'],
            [
                'node_type',
                'node_name',
                'status',
                'started_at',
                'finished_at',
                'duration_ms',
                'output_data',
                'error',
                'sequence',
                'loop_index',
                'parent_frame',
            ],
        );

        return count($rows);
    }

    /**
     * Conditionally flush if the RunContext indicates it's time.
     */
    public function flushIfNeeded(RunContext $context): int
    {
        if (! $context->shouldFlush()) {
            return 0;
        }

        $flushed = $this->flush();
        $context->markFlushed();

        return $flushed;
    }

    /**
     * Get the count of pending (unflushed) rows.
     */
    public function pendingCount(): int
    {
        return count($this->pendingRows);
    }

    /**
     * Check if there are rows waiting to be flushed.
     */
    public function hasPending(): bool
    {
        return ! empty($this->pendingRows);
    }
}
