<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// ── Минутная агрегация для live-дашборда ─────────────────────
Schedule::call(function () {
    require base_path('aggregate-now.php');
})->name('aggregate-minute')->everyMinute()->withoutOverlapping();
