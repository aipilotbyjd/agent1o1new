<?php

namespace App\Jobs;

use App\Ai\Agents\ErrorDiagnosisAgent;
use App\Models\AiFixSuggestion;
use App\Models\Execution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DiagnoseFailedNode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        private int $executionId,
        private string $failedNodeKey,
        private string $errorMessage,
        private string $nodeType,
        private array $nodeConfig,
        private array $inputData,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $execution = Execution::query()->find($this->executionId);

        if ($execution === null) {
            return;
        }

        try {
            $agent = new ErrorDiagnosisAgent(
                errorMessage: $this->errorMessage,
                nodeType: $this->nodeType,
                nodeConfig: $this->nodeConfig,
                inputData: $this->inputData,
            );

            $response = $agent->prompt(
                "Diagnose the error and suggest fixes for this failed workflow node.",
            );

            AiFixSuggestion::query()->create([
                'workspace_id' => $execution->workspace_id,
                'execution_id' => $this->executionId,
                'workflow_id' => $execution->workflow_id,
                'failed_node_key' => $this->failedNodeKey,
                'error_message' => $this->errorMessage,
                'diagnosis' => $response['diagnosis'] ?? '',
                'suggestions' => $response['suggestions'] ?? [],
                'model_used' => config('ai.default', 'openai'),
                'tokens_used' => 0,
                'status' => 'pending',
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI diagnosis failed', [
                'execution_id' => $this->executionId,
                'node_key' => $this->failedNodeKey,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
