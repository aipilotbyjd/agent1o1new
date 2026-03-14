<?php

namespace App\Console\Commands;

use App\Models\PollingTrigger;
use App\Services\PollingTriggerService;
use Illuminate\Console\Command;

class PollTriggersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workflows:poll';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch data for all active polling triggers whose interval has elapsed';

    /**
     * Execute the console command.
     */
    public function handle(PollingTriggerService $pollingTriggerService): int
    {
        $this->info('Starting workflow polling...');

        // Fetch triggers that are active, and its time to poll
        $triggers = PollingTrigger::where('is_active', true)
            ->where('next_poll_at', '<=', now())
            ->get();

        if ($triggers->isEmpty()) {
            $this->info('No polling triggers to run right now.');

            return self::SUCCESS;
        }

        $this->info("Found {$triggers->count()} trigger(s) to poll.");

        $totalTriggered = 0;

        /** @var PollingTrigger $trigger */
        foreach ($triggers as $trigger) {
            $this->info("Polling trigger ID {$trigger->id} associated with workflow {$trigger->workflow_id}...");

            try {
                $triggeredCount = $pollingTriggerService->poll($trigger);
                $totalTriggered += $triggeredCount;
                if ($triggeredCount > 0) {
                    $this->info(" -> Triggered $triggeredCount execution(s).");
                } else {
                    $this->info(' -> No new records found.');
                }
            } catch (\Exception $e) {
                $this->error(" -> Failed to poll trigger ID {$trigger->id}: ".$e->getMessage());
            }
        }

        $this->info("Polling complete. Triggered $totalTriggered execution(s) in total.");

        return self::SUCCESS;
    }
}
