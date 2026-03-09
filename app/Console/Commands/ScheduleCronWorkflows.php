<?php

namespace App\Console\Commands;

use App\Enums\ExecutionMode;
use App\Models\Workflow;
use App\Services\ExecutionService;
use Illuminate\Console\Command;

class ScheduleCronWorkflows extends Command
{
    protected $signature = 'workflows:schedule-cron';

    protected $description = 'Dispatch execution jobs for cron-triggered workflows that are due';

    public function handle(ExecutionService $executionService): int
    {
        $workflows = Workflow::query()
            ->where('is_active', true)
            ->where('trigger_type', 'cron')
            ->whereNotNull('cron_expression')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->with(['currentVersion', 'creator', 'workspace.owner'])
            ->get();

        $dispatched = 0;

        foreach ($workflows as $workflow) {
            if (! $workflow->current_version_id) {
                continue;
            }

            $triggeredBy = $workflow->creator ?? $workflow->workspace->owner;

            if (! $triggeredBy) {
                $this->warn("Skipping workflow {$workflow->id}: no user to trigger as.");

                continue;
            }

            try {
                $executionService->trigger(
                    $workflow,
                    $triggeredBy,
                    ['trigger' => 'cron', 'cron_expression' => $workflow->cron_expression],
                    ExecutionMode::Scheduled,
                );

                $nextRun = $this->calculateNextRun($workflow->cron_expression);
                $workflow->update([
                    'last_cron_run_at' => now(),
                    'next_run_at' => $nextRun,
                ]);

                $dispatched++;
            } catch (\Throwable $e) {
                $this->error("Failed to dispatch workflow {$workflow->id}: {$e->getMessage()}");
            }
        }

        $this->info("Dispatched {$dispatched} cron workflow(s).");

        return self::SUCCESS;
    }

    private function calculateNextRun(string $cronExpression): ?\Carbon\Carbon
    {
        try {
            $cron = new \Cron\CronExpression($cronExpression);

            return \Carbon\Carbon::instance($cron->getNextRunDate());
        } catch (\Throwable) {
            return null;
        }
    }
}
