<?php

namespace App\Console\Commands\Billing;

use App\Models\UsageDailySnapshot;
use App\Models\WorkspaceUsagePeriod;
use Illuminate\Console\Command;

class SnapshotDailyUsage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:snapshot-daily-usage';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create daily usage snapshots for all workspaces with active usage periods';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $yesterday = today()->subDay();

        $periods = WorkspaceUsagePeriod::query()
            ->where('is_current', true)
            ->get();

        $count = 0;

        foreach ($periods as $period) {
            $exists = UsageDailySnapshot::query()
                ->where('workspace_id', $period->workspace_id)
                ->whereDate('snapshot_date', $yesterday)
                ->exists();

            if ($exists) {
                continue;
            }

            UsageDailySnapshot::query()->create([
                'workspace_id' => $period->workspace_id,
                'snapshot_date' => $yesterday,
                'credits_used' => $period->credits_used,
                'executions_total' => $period->executions_total,
                'executions_succeeded' => $period->executions_succeeded,
                'executions_failed' => $period->executions_failed,
                'nodes_executed' => $period->nodes_executed,
                'ai_nodes_executed' => $period->ai_nodes_executed,
            ]);

            $count++;
        }

        $this->info("Created {$count} daily usage snapshot(s).");

        return self::SUCCESS;
    }
}
