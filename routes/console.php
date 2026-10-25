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

// Settles the Production Bonus of each month once the month after it is over
// (docs/slice-20-compensation-plan.md, step 20b). Shortly after midnight in the
// company's time, so the first of the month already has last month's result.
Schedule::command('backoffice:production-bonus')->dailyAt('00:30')->timezone(config('app.display_timezone'))->withoutOverlapping(30);

// Reminds each Production Manager of the Brand Ambassador items due on their
// jobs, such as a yard sign to take down (docs/slice-21-brand-ambassador-plan.md, step 21b).
Schedule::command('backoffice:ambassador-reminders')->dailyAt('07:00')->timezone(config('app.display_timezone'))->withoutOverlapping(30);
