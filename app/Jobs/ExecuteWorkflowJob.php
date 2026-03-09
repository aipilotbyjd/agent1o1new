<?php

namespace App\Jobs;

use App\Models\Execution;
use App\Models\JobStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ExecuteWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public Execution $execution) {}

    public function handle(): void
    {
        $execution = $this->execution;
        $workflow = $execution->workflow;
        $version = $workflow->currentVersion;

        $jobId = (string) Str::uuid();
        $callbackToken = bin2hex(random_bytes(32));
        $partitionCount = (int) config('services.engine.partition_count', 16);
        $partition = $execution->workspace_id % $partitionCount;

        $jobStatus = JobStatus::create([
            'job_id' => $jobId,
            'execution_id' => $execution->id,
            'partition' => $partition,
            'callback_token' => $callbackToken,
            'status' => 'pending',
            'progress' => 0,
        ]);

        $message = $this->buildMessage($jobId, $callbackToken, $partition, $execution, $version);

        $streamKey = "linkflow:jobs:partition:{$partition}";
        $maxLen = (int) config('services.engine.stream_maxlen', 100000);

        Redis::connection()->client()->xadd(
            $streamKey,
            '*',
            ['payload' => json_encode($message)],
        );

        Redis::connection()->client()->xtrim(
            $streamKey,
            'MAXLEN',
            '~',
            $maxLen,
        );

        $jobStatus->markProcessing();
        $execution->start();
    }

    public function failed(\Throwable $exception): void
    {
        $this->execution->fail(['message' => $exception->getMessage()]);

        $jobStatus = JobStatus::query()
            ->where('execution_id', $this->execution->id)
            ->latest()
            ->first();

        $jobStatus?->markFailed(['message' => $exception->getMessage()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMessage(
        string $jobId,
        string $callbackToken,
        int $partition,
        Execution $execution,
        \App\Models\WorkflowVersion $version,
    ): array {
        $workspace = $execution->workspace;
        $workflow = $execution->workflow;

        $variables = [];
        foreach ($workspace->variables()->get() as $variable) {
            $variables[$variable->key] = $variable->is_secret
                ? decrypt($variable->value)
                : $variable->value;
        }

        $apiUrl = config('services.engine.api_url', 'http://linkflow-api:8000');

        $replayPack = $execution->replayPack;
        $deterministicMode = $replayPack?->mode ?? 'capture';

        return [
            'job_id' => $jobId,
            'callback_token' => $callbackToken,
            'execution_id' => $execution->id,
            'workflow_id' => $workflow->id,
            'workspace_id' => $workspace->id,
            'partition' => $partition,
            'priority' => 'default',
            'workflow' => [
                'nodes' => $version->nodes ?? [],
                'edges' => $version->edges ?? [],
                'settings' => $version->settings ?? [],
            ],
            'trigger_data' => $execution->trigger_data ?? [],
            'credentials' => $this->resolveCredentials($workflow, $version),
            'variables' => $variables,
            'callback_url' => "{$apiUrl}/api/v1/jobs/callback",
            'progress_url' => "{$apiUrl}/api/v1/jobs/progress",
            'deterministic' => [
                'mode' => $deterministicMode,
                'seed' => $replayPack?->deterministic_seed ?? (string) Str::uuid(),
                'fixtures' => $deterministicMode === 'replay'
                    ? ($replayPack?->fixtures ?? [])
                    : [],
                'source_execution_id' => $replayPack?->source_execution_id,
            ],
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveCredentials(\App\Models\Workflow $workflow, \App\Models\WorkflowVersion $version): array
    {
        $credentials = [];

        $workflowCredentials = $workflow->credentials()->get();

        foreach ($workflowCredentials as $credential) {
            $nodeId = $credential->pivot->node_id;
            $data = json_decode($credential->data, true) ?? [];

            $credentials[$nodeId] = [
                'id' => $credential->id,
                'type' => $credential->type,
                'name' => $credential->name,
                'data' => $data,
            ];

            $credential->update(['last_used_at' => now()]);
        }

        return $credentials;
    }
}
