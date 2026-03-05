<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExecutionStatus;
use App\Http\Controllers\Controller;
use App\Models\ConnectorCallAttempt;
use App\Models\Execution;
use App\Models\ExecutionNode;
use App\Models\JobStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class JobCallbackController extends Controller
{
    /**
     * Handle final execution callback from the Go engine.
     */
    public function handle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => ['required', 'uuid'],
            'callback_token' => ['required', 'string', 'size:64'],
            'execution_id' => ['required', 'integer'],
            'status' => ['required', 'in:completed,failed,waiting'],
            'duration_ms' => ['nullable', 'integer'],
            'error' => ['nullable', 'array'],
            'nodes' => ['nullable', 'array'],
            'nodes.*.node_id' => ['required', 'string'],
            'nodes.*.node_type' => ['required', 'string'],
            'nodes.*.node_name' => ['nullable', 'string'],
            'nodes.*.status' => ['required', 'in:pending,running,completed,failed,skipped'],
            'nodes.*.output' => ['nullable', 'array'],
            'nodes.*.error' => ['nullable', 'array'],
            'nodes.*.started_at' => ['nullable', 'date'],
            'nodes.*.completed_at' => ['nullable', 'date'],
            'nodes.*.sequence' => ['nullable', 'integer'],
            'deterministic_fixtures' => ['nullable', 'array'],
            'deterministic_fixtures.*.request_fingerprint' => ['nullable', 'string', 'max:64'],
            'deterministic_fixtures.*.request' => ['nullable', 'array'],
            'deterministic_fixtures.*.response' => ['nullable', 'array'],
            'connector_attempts' => ['nullable', 'array'],
            'connector_attempts.*.node_id' => ['nullable', 'string', 'max:100'],
            'connector_attempts.*.connector_key' => ['required_with:connector_attempts', 'string', 'max:120'],
            'connector_attempts.*.connector_operation' => ['required_with:connector_attempts', 'string', 'max:120'],
            'connector_attempts.*.provider' => ['nullable', 'string', 'max:191'],
            'connector_attempts.*.attempt_no' => ['nullable', 'integer', 'min:1', 'max:100'],
            'connector_attempts.*.is_retry' => ['nullable', 'boolean'],
            'connector_attempts.*.status' => ['required_with:connector_attempts', 'in:success,client_error,server_error,timeout,network_error,cancelled'],
            'connector_attempts.*.status_code' => ['nullable', 'integer', 'min:100', 'max:599'],
            'connector_attempts.*.duration_ms' => ['nullable', 'integer', 'min:0'],
            'connector_attempts.*.request_fingerprint' => ['nullable', 'string', 'size:64'],
            'connector_attempts.*.idempotency_key' => ['nullable', 'string', 'max:191'],
            'connector_attempts.*.error_code' => ['nullable', 'string', 'max:120'],
            'connector_attempts.*.error_message' => ['nullable', 'string'],
            'connector_attempts.*.happened_at' => ['nullable', 'date'],
            'connector_attempts.*.meta' => ['nullable', 'array'],
        ]);

        $jobStatus = JobStatus::query()->where('job_id', $validated['job_id'])->first();

        if (! $jobStatus) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        if (! hash_equals($jobStatus->callback_token, $validated['callback_token'])) {
            return response()->json(['error' => 'Invalid callback token'], 401);
        }

        $execution = Execution::find($validated['execution_id']);

        if (! $execution) {
            return response()->json(['error' => 'Execution not found'], 404);
        }

        if ($execution->id !== $jobStatus->execution_id) {
            return response()->json(['error' => 'Execution does not match job'], 403);
        }

        // Idempotency — if job is already terminal, return success without DB writes
        if (in_array($jobStatus->status, ['completed', 'failed'])) {
            return response()->json([
                'success' => true,
                'execution_id' => $execution->id,
                'status' => $execution->status->value,
                'idempotent' => true,
            ]);
        }

        // Upsert execution nodes
        if (! empty($validated['nodes'])) {
            $this->upsertExecutionNodes($execution, $validated['nodes']);
        }

        // Update execution status
        $executionStatus = ExecutionStatus::from($validated['status']);
        $durationMs = $validated['duration_ms'] ?? null;

        match ($executionStatus) {
            ExecutionStatus::Completed => $execution->complete(null, $durationMs),
            ExecutionStatus::Failed => $execution->fail($validated['error'] ?? null, $durationMs),
            ExecutionStatus::Waiting => $execution->update([
                'status' => ExecutionStatus::Waiting,
                'duration_ms' => $durationMs,
            ]),
            default => null,
        };

        // Update job status
        match ($executionStatus) {
            ExecutionStatus::Completed => $jobStatus->markCompleted(),
            ExecutionStatus::Failed => $jobStatus->markFailed($validated['error'] ?? null),
            ExecutionStatus::Waiting => $jobStatus->updateProgress(90),
            default => null,
        };

        // Store connector attempts
        if (! empty($validated['connector_attempts'])) {
            $this->ingestConnectorAttempts($execution, $validated['connector_attempts']);
        }

        // Append deterministic fixtures to replay pack
        if (! empty($validated['deterministic_fixtures'])) {
            $this->appendFixtures($execution, $validated['deterministic_fixtures']);
        }

        // Publish SSE event
        $this->publishSseEvent($execution, $executionStatus);

        // Update workflow stats
        $this->updateWorkflowStats($execution);

        return response()->json([
            'success' => true,
            'execution_id' => $execution->id,
            'status' => $executionStatus->value,
        ]);
    }

    /**
     * Handle mid-run progress updates from the Go engine.
     */
    public function progress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => ['required', 'uuid'],
            'callback_token' => ['required', 'string', 'size:64'],
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
            'current_node' => ['nullable', 'string'],
            'connector_attempts' => ['nullable', 'array'],
            'deterministic_fixtures' => ['nullable', 'array'],
        ]);

        $jobStatus = JobStatus::query()->where('job_id', $validated['job_id'])->first();

        if (! $jobStatus) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        if (! hash_equals($jobStatus->callback_token, $validated['callback_token'])) {
            return response()->json(['error' => 'Invalid callback token'], 401);
        }

        $jobStatus->updateProgress($validated['progress']);

        $execution = $jobStatus->execution;

        if (! empty($validated['connector_attempts']) && $execution) {
            $this->ingestConnectorAttempts($execution, $validated['connector_attempts']);
        }

        if (! empty($validated['deterministic_fixtures']) && $execution) {
            $this->appendFixtures($execution, $validated['deterministic_fixtures']);
        }

        // Publish SSE progress event
        if ($execution) {
            $this->publishSseRawEvent($execution->id, [
                'event' => 'execution.progress',
                'execution_id' => $execution->id,
                'data' => [
                    'progress' => $validated['progress'],
                    'current_node' => $validated['current_node'] ?? null,
                ],
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function upsertExecutionNodes(Execution $execution, array $nodes): void
    {
        foreach ($nodes as $nodeData) {
            $durationMs = null;
            if (! empty($nodeData['started_at']) && ! empty($nodeData['completed_at'])) {
                $started = \Carbon\Carbon::parse($nodeData['started_at']);
                $completed = \Carbon\Carbon::parse($nodeData['completed_at']);
                $durationMs = (int) $started->diffInMilliseconds($completed);
            }

            ExecutionNode::updateOrCreate(
                [
                    'execution_id' => $execution->id,
                    'node_id' => $nodeData['node_id'],
                ],
                [
                    'node_type' => $nodeData['node_type'],
                    'node_name' => $nodeData['node_name'] ?? null,
                    'status' => $nodeData['status'],
                    'started_at' => $nodeData['started_at'] ?? null,
                    'finished_at' => $nodeData['completed_at'] ?? null,
                    'duration_ms' => $durationMs,
                    'output_data' => $nodeData['output'] ?? null,
                    'error' => $nodeData['error'] ?? null,
                    'sequence' => $nodeData['sequence'] ?? null,
                ],
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $attempts
     */
    private function ingestConnectorAttempts(Execution $execution, array $attempts): void
    {
        $nodeMap = $execution->nodes()->pluck('id', 'node_id')->toArray();

        foreach ($attempts as $attempt) {
            ConnectorCallAttempt::create([
                'execution_id' => $execution->id,
                'execution_node_id' => $nodeMap[$attempt['node_id'] ?? ''] ?? null,
                'workspace_id' => $execution->workspace_id,
                'workflow_id' => $execution->workflow_id,
                'connector_key' => $attempt['connector_key'],
                'connector_operation' => $attempt['connector_operation'],
                'provider' => $attempt['provider'] ?? null,
                'attempt_no' => $attempt['attempt_no'] ?? 1,
                'is_retry' => $attempt['is_retry'] ?? false,
                'status' => $attempt['status'],
                'status_code' => $attempt['status_code'] ?? null,
                'duration_ms' => $attempt['duration_ms'] ?? null,
                'request_fingerprint' => $attempt['request_fingerprint'] ?? null,
                'idempotency_key' => $attempt['idempotency_key'] ?? null,
                'error_code' => $attempt['error_code'] ?? null,
                'error_message' => $attempt['error_message'] ?? null,
                'happened_at' => $attempt['happened_at'] ?? now(),
                'meta' => $attempt['meta'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $fixtures
     */
    private function appendFixtures(Execution $execution, array $fixtures): void
    {
        $replayPack = $execution->replayPack;
        if (! $replayPack) {
            return;
        }

        $existing = $replayPack->fixtures ?? [];
        $existingFingerprints = collect($existing)->pluck('request_fingerprint')->filter()->all();

        foreach ($fixtures as $fixture) {
            $fingerprint = $fixture['request_fingerprint'] ?? null;
            if ($fingerprint && in_array($fingerprint, $existingFingerprints)) {
                continue;
            }
            $existing[] = $fixture;
            if ($fingerprint) {
                $existingFingerprints[] = $fingerprint;
            }
        }

        $replayPack->update(['fixtures' => $existing]);
    }

    private function publishSseEvent(Execution $execution, ExecutionStatus $status): void
    {
        $event = match ($status) {
            ExecutionStatus::Completed => 'execution.completed',
            ExecutionStatus::Failed => 'execution.failed',
            default => 'execution.updated',
        };

        $this->publishSseRawEvent($execution->id, [
            'event' => $event,
            'execution_id' => $execution->id,
            'data' => [
                'status' => $status->value,
                'total_duration_ms' => $execution->duration_ms,
                'node_count' => $execution->nodes()->count(),
                'error' => $status === ExecutionStatus::Failed ? ($execution->error['message'] ?? null) : null,
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function publishSseRawEvent(int $executionId, array $payload): void
    {
        $streamKey = "execution:{$executionId}:events";

        try {
            Redis::connection()->client()->xadd($streamKey, '*', ['payload' => json_encode($payload)]);
            Redis::connection()->client()->expire($streamKey, 300);
        } catch (\Throwable) {
            // SSE publishing is best-effort — don't fail the callback
        }
    }

    private function updateWorkflowStats(Execution $execution): void
    {
        $workflow = $execution->workflow;
        if (! $workflow) {
            return;
        }

        $total = $workflow->executions()->count();
        $completed = $workflow->executions()->where('status', ExecutionStatus::Completed)->count();

        $workflow->update([
            'execution_count' => $total,
            'last_executed_at' => $execution->finished_at ?? now(),
            'success_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
        ]);
    }
}
