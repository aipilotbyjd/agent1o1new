<?php

namespace App\Engine\Nodes;

use App\Engine\Contracts\NodeHandler;
use App\Engine\NodeResult;
use App\Engine\Runners\NodePayload;
use App\Enums\ExecutionMode;
use App\Models\Workflow;
use App\Services\ExecutionService;

/**
 * Triggers another workflow as a sub-workflow execution.
 */
class SubWorkflowNode implements NodeHandler
{
    public function __construct(private ExecutionService $executionService) {}

    public function handle(NodePayload $payload): NodeResult
    {
        $startTime = hrtime(true);

        try {
            $workflowId = $payload->config['workflow_id'] ?? null;

            if (! $workflowId) {
                return NodeResult::failed('No workflow_id configured.', 'SUB_WORKFLOW_MISSING_ID');
            }

            $workflow = Workflow::query()->find($workflowId);

            if (! $workflow) {
                return NodeResult::failed("Workflow [{$workflowId}] not found.", 'SUB_WORKFLOW_NOT_FOUND');
            }

            $triggeredBy = \App\Models\User::query()->find($payload->executionMeta['triggered_by'] ?? 0);

            if (! $triggeredBy) {
                return NodeResult::failed('Cannot resolve triggering user.', 'SUB_WORKFLOW_NO_USER');
            }

            $execution = $this->executionService->trigger(
                $workflow,
                $triggeredBy,
                $payload->inputData,
                ExecutionMode::SubWorkflow,
            );

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return NodeResult::completed([
                'sub_execution_id' => $execution->id,
                'sub_workflow_id' => $workflow->id,
                'status' => $execution->status->value,
            ], $durationMs);
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            return NodeResult::failed($e->getMessage(), 'SUB_WORKFLOW_ERROR', $durationMs);
        }
    }
}
