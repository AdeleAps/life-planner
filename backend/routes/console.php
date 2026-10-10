<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('planner:queue-evening-review')
    ->dailyAt('20:00')
    ->timezone('Europe/Riga')
    ->withoutOverlapping(10);
