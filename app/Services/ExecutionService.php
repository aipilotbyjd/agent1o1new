<?php

namespace App\Services;

use App\Enums\ExecutionMode;
use App\Enums\ExecutionStatus;
use App\Exceptions\ApiException;
use App\Jobs\ExecuteWorkflowJob;
use App\Models\Execution;
use App\Models\ExecutionReplayPack;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Support\Str;

class ExecutionService
{
    /**
     * Trigger a new workflow execution.
     */
    public function trigger(
        Workflow $workflow,
        User $user,
        ?array $triggerData = null,
        ExecutionMode $mode = ExecutionMode::Manual,
    ): Execution {
        if (! $workflow->is_active) {
            throw ApiException::unprocessable('Workflow is not active.');
        }

        if (! $workflow->current_version_id) {
            throw ApiException::unprocessable('Workflow has no published version.');
        }

        $workflow->loadMissing('currentVersion');

        $execution = Execution::create([
            'workflow_id' => $workflow->id,
            'workspace_id' => $workflow->workspace_id,
            'status' => ExecutionStatus::Pending,
            'mode' => $mode,
            'triggered_by' => $user->id,
            'trigger_data' => $triggerData,
            'attempt' => 1,
            'max_attempts' => $workflow->currentVersion->settings['retry']['max_attempts'] ?? 1,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);

        $this->captureReplayPack($execution, $workflow);

        ExecuteWorkflowJob::dispatch($execution)
            ->onQueue('workflows-default');

        return $execution;
    }

    /**
     * Retry a failed execution by creating a child execution.
     */
    public function retry(Execution $execution, User $user): Execution
    {
        if ($execution->status !== ExecutionStatus::Failed) {
            throw ApiException::unprocessable('Only failed executions can be retried.');
        }

        if ($execution->attempt >= $execution->max_attempts) {
            throw ApiException::unprocessable('Maximum retry attempts reached.');
        }

        $workflow = $execution->workflow;

        $childExecution = Execution::create([
            'workflow_id' => $workflow->id,
            'workspace_id' => $execution->workspace_id,
            'status' => ExecutionStatus::Pending,
            'mode' => ExecutionMode::Retry,
            'triggered_by' => $user->id,
            'trigger_data' => $execution->trigger_data,
            'attempt' => $execution->attempt + 1,
            'max_attempts' => $execution->max_attempts,
            'parent_execution_id' => $execution->id,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);

        $this->captureReplayPack($childExecution, $workflow);

        ExecuteWorkflowJob::dispatch($childExecution)
            ->onQueue('workflows-default');

        return $childExecution;
    }

    /**
     * Cancel an active execution.
     */
    public function cancel(Execution $execution): Execution
    {
        if (! $execution->canCancel()) {
            throw ApiException::unprocessable('This execution cannot be cancelled.');
        }

        $execution->cancel();

        // Signal the Go engine to stop this execution
        $this->publishCancelSignal($execution);

        return $execution->refresh();
    }

    public function delete(Execution $execution): void
    {
        $execution->delete();
    }

    /**
     * Get aggregated execution stats for a workspace.
     *
     * @return array<string, mixed>
     */
    public function stats(Workspace $workspace, ?int $workflowId = null): array
    {
        $query = $workspace->executions();

        if ($workflowId) {
            $query->where('workflow_id', $workflowId);
        }

        $total = $query->count();
        $completed = (clone $query)->where('status', ExecutionStatus::Completed)->count();
        $failed = (clone $query)->where('status', ExecutionStatus::Failed)->count();
        $running = (clone $query)->where('status', ExecutionStatus::Running)->count();
        $pending = (clone $query)->where('status', ExecutionStatus::Pending)->count();
        $cancelled = (clone $query)->where('status', ExecutionStatus::Cancelled)->count();
        $avgDuration = (clone $query)->whereNotNull('duration_ms')->avg('duration_ms');

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'running' => $running,
            'pending' => $pending,
            'cancelled' => $cancelled,
            'success_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
            'avg_duration_ms' => $avgDuration ? (int) round($avgDuration) : null,
        ];
    }

    private function captureReplayPack(Execution $execution, Workflow $workflow): void
    {
        $version = $workflow->currentVersion;

        ExecutionReplayPack::create([
            'execution_id' => $execution->id,
            'workspace_id' => $execution->workspace_id,
            'workflow_id' => $workflow->id,
            'source_execution_id' => null,
            'mode' => 'capture',
            'deterministic_seed' => (string) Str::uuid(),
            'workflow_snapshot' => [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'nodes' => $version->nodes ?? [],
                'edges' => $version->edges ?? [],
                'settings' => $version->settings ?? [],
            ],
            'trigger_snapshot' => $execution->trigger_data,
            'fixtures' => [],
            'environment_snapshot' => [
                'app_env' => config('app.env'),
                'app_url' => config('app.url'),
                'captured_by' => 'api-dispatch',
            ],
            'captured_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    private function publishCancelSignal(Execution $execution): void
    {
        $jobStatus = $execution->jobStatus;
        if (! $jobStatus) {
            return;
        }

        try {
            $payload = json_encode([
                'action' => 'cancel',
                'job_id' => $jobStatus->job_id,
                'execution_id' => $execution->id,
                'timestamp' => now()->toIso8601String(),
            ]);

            $streamKey = "linkflow:jobs:cancel:{$jobStatus->partition}";
            \Illuminate\Support\Facades\Redis::connection('engine')->client()->xadd($streamKey, '*', ['payload' => $payload]);
            \Illuminate\Support\Facades\Redis::connection('engine')->client()->expire($streamKey, 300);
        } catch (\Throwable) {
            // Cancel signal is best-effort
        }
    }
}
