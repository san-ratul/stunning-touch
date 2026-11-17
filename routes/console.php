<?php

use App\Support\ScheduledArtisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Shared-hosting-friendly scheduler
|--------------------------------------------------------------------------
| No queue workers or daemons. The host triggers `schedule:run` once per
| minute (or the protected /run-scheduler/{secret} endpoint); jobs use
| onOneServer-style locking via cache mutexes to avoid double execution.
|
| Every task is registered as a `Schedule::call()` event that runs the
| Artisan command IN-PROCESS via ScheduledArtisan::call(). Laravel's
| `Schedule::command()` events spawn a `php artisan ...` subprocess
| through Symfony Process, which needs proc_open() — disabled on the
| production cPanel shared host. In-process callback events have no such
| requirement, and each event is named so the cache-based overlapping and
| one-server mutexes keep working exactly as before.
|
| blog:auto-pipeline fires every five minutes and executes at most one
| bounded article attempt for the earliest due publishing slot, decided
| from automation_settings (slots, posts_per_day, timezone). Interrupted
| runs are resumed safely by later ticks. The legacy blog:generate-daily
| command remains available for manual/backward-compatible runs.
*/

Schedule::call(fn () => ScheduledArtisan::call('blog:auto-pipeline'))
    ->name('blog:auto-pipeline')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(fn () => ScheduledArtisan::call('blog:publish-scheduled'))
    ->name('blog:publish-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(fn () => ScheduledArtisan::call('analytics:aggregate', ['--cleanup' => true]))
    ->name('analytics:aggregate')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(fn () => ScheduledArtisan::call('blog:cleanup-history'))
    ->name('blog:cleanup-history')
    ->weeklyOn(1, '04:45')
    ->withoutOverlapping()
    ->onOneServer();
