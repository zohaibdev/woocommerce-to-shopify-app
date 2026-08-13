<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for stalled migrations. Shared hosting without a scheduler cron
// should call `php artisan migration:watchdog` directly instead (see DEPLOYMENT.md).
Schedule::command('migration:watchdog')->everyFiveMinutes()->withoutOverlapping();
