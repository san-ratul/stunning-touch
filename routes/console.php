<?php

use App\Console\Commands\AggregateAnalytics;
use App\Console\Commands\CleanupGenerationHistory;
use App\Console\Commands\PublishScheduledPosts;
use App\Console\Commands\RunAutoPipeline;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Shared-hosting-friendly scheduler
|--------------------------------------------------------------------------
| No queue workers or daemons. The host triggers `schedule:run` once per
| minute (or the protected /run-scheduler/{secret} endpoint); jobs use
| onOneServer-style locking via cache mutexes to avoid double execution.
|
| blog:auto-pipeline fires every five minutes and executes at most one
| bounded article attempt for the earliest due publishing slot, decided
| from automation_settings (slots, posts_per_day, timezone). Interrupted
| runs are resumed safely by later ticks. The legacy blog:generate-daily
| command remains available for manual/backward-compatible runs.
*/

Schedule::command(RunAutoPipeline::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(PublishScheduledPosts::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(AggregateAnalytics::class.' --cleanup')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(CleanupGenerationHistory::class)
    ->weeklyOn(1, '04:45')
    ->withoutOverlapping()
    ->onOneServer();
