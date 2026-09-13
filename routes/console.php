<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('monitoring:run')->daily();
// Runs every minute and only enqueues due subscriptions onto the `fiscal`
// queue (see FiscalSyncJob): provision `php artisan queue:work
// --queue=fiscal,default` or sync silently never runs. Overlap + one-server
// guards keep every-minute dispatches from duplicating work.
Schedule::command('fiscal:sync-dispatch')->everyMinute()->withoutOverlapping()->onOneServer();
