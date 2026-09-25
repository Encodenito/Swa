<?php

use Illuminate\Support\Facades\Schedule;

// Scheduler times follow config('app.timezone') = Asia/Manila. Run locally with `php artisan schedule:work`.
// Mutex expiries are short so a crashed run never blocks a job for long.

Schedule::command('reservations:expire')->everyMinute()->withoutOverlapping(5);

Schedule::command('reservations:forfeit')->hourly()->withoutOverlapping(10);

Schedule::command('reservations:remind')->dailyAt('08:00')->withoutOverlapping(30);

Schedule::command('stock:low-alert')->dailyAt('08:00')->withoutOverlapping(30);
