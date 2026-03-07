<?php

namespace App\Console\Commands\Billing;

use App\Models\WorkspaceUsagePeriod;
use App\Services\CreditMeterService;
use Illuminate\Console\Command;

class ResetMonthlyCredits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:reset-monthly-credits';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Roll over usage periods that have reached their period end date';

    /**
     * Execute the console command.
     */
    public function handle(CreditMeterService $service): int
    {
        $periods = WorkspaceUsagePeriod::query()
            ->where('is_current', true)
            ->where('period_end', '<=', today())
            ->with('workspace')
            ->get();

        $count = 0;

        foreach ($periods as $period) {
            $service->rolloverPeriod($period->workspace);
            $count++;
        }

        $this->info("Rolled over {$count} usage period(s).");

        return self::SUCCESS;
    }
}
