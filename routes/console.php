<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('mobile:sync --once')
    ->everyMinute()
    ->withoutOverlapping()
    ->environments('nativephp')
    ->appendOutputTo(storage_path('logs/mobile-sync.log'));
