<?php

namespace App\Console\Commands\Billing;

use App\Enums\CreditPackStatus;
use App\Models\CreditPack;
use Illuminate\Console\Command;

class ExpireCreditPacks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:expire-credit-packs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire active credit packs that have passed their expiration date';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = CreditPack::query()
            ->where('status', CreditPackStatus::Active)
            ->where('expires_at', '<=', now())
            ->update(['status' => CreditPackStatus::Expired]);

        $this->info("Expired {$count} credit pack(s).");

        return self::SUCCESS;
    }
}
