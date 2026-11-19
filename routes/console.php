<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Shared hosting: one cron entry runs `php artisan schedule:run` every minute.
Schedule::command('imports:run --seconds=50')->everyMinute()->withoutOverlapping();

// Phase 7: nightly verified backup (keeps 14) and read-only integrity checks. Failures are logged.
Schedule::command('erp:backup --keep=14')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('erp:integrity')->dailyAt('02:15');
