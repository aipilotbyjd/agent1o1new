<?php

namespace App\Engine\Persistence;

use App\Engine\Data\Suspension;
use App\Engine\RunContext;
use App\Models\Execution;
use App\Models\ExecutionCheckpoint;

class CheckpointStore
{
    /**
     * Save execution state as a checkpoint.
     */
    public function save(Execution $execution, RunContext $context, Suspension $suspension): void
    {
        $frontierSnapshot = $context->snapshot();
        $outputSnapshot = $context->outputs->snapshot();

        ExecutionCheckpoint::updateOrCreate(
            ['execution_id' => $execution->id],
            [
                'frontier_state' => [
                    'ready_queue' => $frontierSnapshot['ready_queue'],
                    'remaining_in_degree' => $frontierSnapshot['remaining_in_degree'],
                    'completed_nodes' => $frontierSnapshot['completed_nodes'],
                    'variables' => $frontierSnapshot['variables'],
                ],
                'output_refs' => $outputSnapshot,
                'frame_stack' => $frontierSnapshot['frame_stack'],
                'next_sequence' => $frontierSnapshot['next_sequence'],
                'suspend_reason' => $suspension->reason,
                'resume_at' => $suspension->resumeAt,
                'checkpoint_version' => 1,
            ],
        );
    }

    /**
     * Load a checkpoint for an execution.
     */
    public function load(int $executionId): ?ExecutionCheckpoint
    {
        return ExecutionCheckpoint::where('execution_id', $executionId)->first();
    }

    /**
     * Delete a checkpoint (on terminal state).
     */
    public function delete(int $executionId): void
    {
        ExecutionCheckpoint::where('execution_id', $executionId)->delete();
    }
}
