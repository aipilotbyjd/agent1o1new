<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('billing:snapshot-daily-usage')->dailyAt('00:05');
Schedule::command('billing:expire-credit-packs')->dailyAt('00:10');
Schedule::command('billing:reset-monthly-credits')->daily();
