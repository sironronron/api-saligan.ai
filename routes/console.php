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
