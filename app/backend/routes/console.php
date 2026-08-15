<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep something draining the queue even when nobody has the app open.
//
// A self-started worker lives 55 seconds, so this has to run every minute or
// there is a hole in coverage. It used to be hung off the five-minute watchdog
// below, which gave an unwatched migration 55 seconds of work out of every 300
// — the app told merchants they could close the tab, and closing it made their
// run five times slower.
//
// Deliberately a separate command from the watchdog: this is two queries, the
// watchdog sweeps every active migration.
Schedule::command('queue:keepalive')->everyMinute()->withoutOverlapping();

// Safety net for stalled migrations. Shared hosting without a scheduler cron
// should call `php artisan migration:watchdog` directly instead (see DEPLOYMENT.md).
Schedule::command('migration:watchdog')->everyFiveMinutes()->withoutOverlapping();
