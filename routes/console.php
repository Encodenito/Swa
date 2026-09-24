<?php

use Illuminate\Support\Facades\Schedule;

// Scheduler times follow config('app.timezone') = Asia/Manila. Run locally with `php artisan schedule:work`.

Schedule::command('reservations:expire')->everyMinute()->withoutOverlapping();
