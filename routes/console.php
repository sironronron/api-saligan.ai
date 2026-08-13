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
