<?php

use App\Console\Commands\BotTick;
use App\Console\Commands\DispatchPendingMessages;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(DispatchPendingMessages::class)
    ->everyMinute()->withoutOverlapping();

Schedule::command(BotTick::class)->everyFiveSeconds()->withoutOverlapping();
