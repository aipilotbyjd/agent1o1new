<?php

namespace App\Jobs;

use App\Engine\WorkflowEngine;
use App\Enums\ExecutionStatus;
use App\Models\Execution;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ResumeWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public int $maxExceptions = 1;

    public function __construct(public Execution $execution) {}

    public function handle(WorkflowEngine $engine): void
    {
        $this->execution->refresh();

        if ($this->execution->status !== ExecutionStatus::Waiting) {
            Log::info("ResumeWorkflowJob skipped — execution {$this->execution->id} is no longer waiting.", [
                'status' => $this->execution->status->value,
            ]);

            return;
        }

        $engine->resume($this->execution);
    }

    public function failed(\Throwable $exception): void
    {
        $this->execution->fail([
            'message' => $exception->getMessage(),
            'type' => 'RESUME_FAILED',
        ]);
    }
}
