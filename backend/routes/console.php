<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('activity:deliver')->everyMinute()->withoutOverlapping();

// The partition runway. If this stops running, every insert into audit_logs and
// activity_events fails at once, six months from now.
Schedule::command('partitions:ensure')->dailyAt('02:00');
Schedule::command('activity:prune')->weeklyOn(7, '03:00');
Schedule::command('assessments:refresh-stats')->dailyAt('04:00');
