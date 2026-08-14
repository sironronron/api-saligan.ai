<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('crawl:legal-sources')
    ->cron(config('saligan.crawler.schedule'))
    ->when(fn () => config('saligan.crawler.enabled'))
    ->withoutOverlapping()
    ->onOneServer();

// Trials also end on message allowance, which is caught inline as the messages
// are spent. This sweep only covers the calendar side, so a daily tick is
// enough — a trial cannot cross a day boundary more than once a day.
Schedule::command('trials:warn')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->onOneServer();

// Closed cases get a 30-day grace period before they move to the archive; a
// once-daily sweep keeps that promise without chasing exact timestamps.
Schedule::command('cases:archive-closed')
    ->dailyAt('00:30')
    ->withoutOverlapping()
    ->onOneServer();

// Batched document classification. Submitting waits a while so a batch is
// worth sending; collecting runs more often because a batch can end at any
// point after that. Both are no-ops unless batching is switched on, and both
// are safe to run twice — a submitted request is never resubmitted.
Schedule::command('documents:classify-submit')
    ->everyFifteenMinutes()
    ->when(fn () => config('saligan.documents.classification.batch.enabled'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('documents:classify-collect')
    ->everyFiveMinutes()
    ->when(fn () => config('saligan.documents.classification.batch.enabled'))
    ->withoutOverlapping()
    ->onOneServer();

// Batched legal digests, on the same submit-slowly/collect-often shape. The
// crawl runs nightly and queues everything it fetched, so submitting hourly is
// frequent enough to clear a run without splitting it into needless batches.
Schedule::command('saligan:digest-submit')
    ->hourly()
    ->when(fn () => config('saligan.crawler.digest.batch.enabled'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('saligan:digest-collect')
    ->everyFifteenMinutes()
    ->when(fn () => config('saligan.crawler.digest.batch.enabled'))
    ->withoutOverlapping()
    ->onOneServer();

// Deadline reminders run just after UTC midnight so a "due today" email lands
// on the morning it is due (01:00 UTC is 09:00 in the Philippines). The sweep
// stamps each deadline it reminds, so daily ticks are enough — a due date
// cannot cross the lead window more than once unless it is moved.
Schedule::command('deadlines:remind')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onOneServer();
