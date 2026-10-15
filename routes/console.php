<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The call queue's safety net and heartbeat. It runs inline in the scheduler
// process: no queue worker is used. The overlap lock frees itself after five
// minutes and is kept in the application's own cache store.
Schedule::command('backoffice:queue-tick')->everyMinute()->withoutOverlapping(5);

// Deletes from storage the files removed more than 30 days ago
// (docs/slice-16-document-storage-plan.md, step 16a).
Schedule::command('backoffice:files-purge')->dailyAt('03:15')->withoutOverlapping(60);
