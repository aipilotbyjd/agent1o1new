<?php

namespace App\Jobs;

use App\Engine\WorkflowEngine;
use App\Models\Execution;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public int $maxExceptions = 1;

    public function __construct(public Execution $execution) {}

    public function handle(WorkflowEngine $engine): void
    {
        $engine->run($this->execution);
    }

    public function failed(\Throwable $exception): void
    {
        $this->execution->fail(['message' => $exception->getMessage()]);
    }
}
