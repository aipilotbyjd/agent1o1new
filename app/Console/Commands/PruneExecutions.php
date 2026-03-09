<?php

namespace App\Console\Commands;

use App\Models\Execution;
use App\Models\WorkspaceSetting;
use Illuminate\Console\Command;

class PruneExecutions extends Command
{
    protected $signature = 'executions:prune {--days= : Override retention days for all workspaces} {--workspace= : Prune only a specific workspace}';

    protected $description = 'Delete old terminal executions based on workspace retention settings';

    public function handle(): int
    {
        $overrideDays = $this->option('days') ? (int) $this->option('days') : null;
        $workspaceId = $this->option('workspace') ? (int) $this->option('workspace') : null;

        $settingsQuery = WorkspaceSetting::query();

        if ($workspaceId) {
            $settingsQuery->where('workspace_id', $workspaceId);
        }

        $totalDeleted = 0;

        if ($overrideDays && ! $workspaceId) {
            $cutoff = now()->subDays($overrideDays);

            $deleted = Execution::query()
                ->terminal()
                ->where('created_at', '<', $cutoff)
                ->delete();

            $totalDeleted += $deleted;
            $this->info("Pruned {$deleted} executions older than {$overrideDays} days.");
        } else {
            $settings = $settingsQuery->get();

            foreach ($settings as $setting) {
                $days = $overrideDays ?? $setting->execution_retention_days;
                $cutoff = now()->subDays($days);

                $deleted = Execution::query()
                    ->where('workspace_id', $setting->workspace_id)
                    ->terminal()
                    ->where('created_at', '<', $cutoff)
                    ->delete();

                $totalDeleted += $deleted;

                if ($deleted > 0) {
                    $this->line("Workspace {$setting->workspace_id}: pruned {$deleted} executions (retention: {$days} days).");
                }
            }
        }

        $this->info("Total pruned: {$totalDeleted} execution(s).");

        return self::SUCCESS;
    }
}
