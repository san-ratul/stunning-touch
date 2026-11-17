<?php

namespace Tests\Feature;

use App\Models\AiGeneration;
use App\Models\AnalyticsDaily;
use App\Models\AuditLog;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\PageView;
use App\Models\Tool;
use App\Models\ToolUsage;
use App\Models\Visitor;
use App\Support\ScheduledArtisan;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Regression tests for the shared-hosting scheduler fix.
 *
 * Laravel's `Schedule::command()` events execute `php artisan ...`
 * subprocesses through Symfony Process, which needs proc_open() —
 * disabled on the production cPanel host. Every scheduled task is
 * therefore registered as an in-process `Schedule::call()` callback
 * that runs the Artisan command via ScheduledArtisan. These tests lock
 * that behaviour in: same frequencies, same arguments, same overlap and
 * one-server locks, and execution that never spawns a subprocess.
 */
class SchedulerInProcessTest extends TestCase
{
    use RefreshDatabase;

    /** 10:02 is not due for the 5-minute (10:00/10:05), daily (03:15) or weekly (Mon 04:45) tasks; only the every-minute task is due. */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 10:02:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function bootSchedule(): array
    {
        $this->artisan('schedule:list');

        return Schedule::events();
    }

    /**
     * Simulate a fresh cron tick: production spawns a new PHP process for
     * every `schedule:run`, so the framework's per-process memoization of
     * the one-server decision must be cleared before a second run.
     */
    protected function simulateFreshSchedulerProcess(): void
    {
        (function (): void {
            $this->mutexCache = [];
        })->call(Schedule::getFacadeRoot());
    }

    protected function eventNamed(array $events, string $name): CallbackEvent
    {
        foreach ($events as $event) {
            if ($event->getSummaryForDisplay() === $name) {
                return $event;
            }
        }

        $this->fail("Scheduled event [{$name}] is not registered.");
    }

    protected function dueScheduledPost(array $overrides = []): BlogPost
    {
        return BlogPost::create($overrides + [
            'title' => 'Scheduled Article',
            'slug' => 'scheduled-article',
            'content' => '<h2>Heading</h2><p>'.trim(str_repeat('word ', 50)).'</p>',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(5),
            'source' => 'manual',
        ]);
    }

    public function test_schedule_defines_all_tasks_with_original_frequencies_and_locks(): void
    {
        $events = $this->bootSchedule();

        $this->assertCount(4, $events);

        $expected = [
            'blog:auto-pipeline' => '*/5 * * * *',
            'blog:publish-scheduled' => '* * * * *',
            'analytics:aggregate' => '15 3 * * *',
            'blog:cleanup-history' => '45 4 * * 1',
        ];

        foreach ($expected as $name => $expression) {
            $event = $this->eventNamed($events, $name);

            $this->assertSame($expression, $event->expression, "Frequency changed for [{$name}].");
            $this->assertTrue($event->withoutOverlapping, "withoutOverlapping lost for [{$name}].");
            $this->assertTrue($event->onOneServer, "onOneServer lost for [{$name}].");
            $this->assertFalse($event->runInBackground, "[{$name}] must run in the foreground.");
            $this->assertSame(config('app.timezone'), $event->timezone, "Timezone changed for [{$name}].");
        }
    }

    public function test_all_scheduled_tasks_are_in_process_callbacks_without_symfony_process(): void
    {
        $events = $this->bootSchedule();

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            // Base `Event` objects execute through Symfony Process (proc_open).
            // Only CallbackEvent instances execute inside the current process.
            $this->assertSame(CallbackEvent::class, $event::class);
        }
    }

    public function test_scheduler_executes_due_task_in_process(): void
    {
        $post = $this->dueScheduledPost();

        Artisan::call('schedule:run');

        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertNotNull($post->published_at);
        $this->assertNull($post->scheduled_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'post.published')->count());
    }

    public function test_scheduler_does_not_execute_the_same_task_twice_per_minute(): void
    {
        $this->dueScheduledPost();

        $ran = [];
        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) use (&$ran): void {
            $ran[] = $event->task->getSummaryForDisplay();
        });

        Artisan::call('schedule:run');

        $this->simulateFreshSchedulerProcess();

        Artisan::call('schedule:run');

        $runs = array_count_values($ran);

        $this->assertArrayHasKey('blog:publish-scheduled', $runs);
        $this->assertSame(1, $runs['blog:publish-scheduled'], 'The every-minute task ran more than once in a single minute.');
        $this->assertSame(1, AuditLog::query()->where('action', 'post.published')->count());
    }

    public function test_without_overlapping_lock_still_prevents_concurrent_runs(): void
    {
        $post = $this->dueScheduledPost();

        $lock = Cache::lock('framework/schedule-'.sha1('blog:publish-scheduled'), 60);
        $this->assertTrue($lock->get());

        Artisan::call('schedule:run');

        $post->refresh();
        $this->assertSame('scheduled', $post->status, 'A held overlap lock must skip the task.');
        $this->assertNull($post->published_at);

        $lock->release();

        // Advance to the next minute so the one-server mutex does not skip the retry.
        Carbon::setTestNow('2026-06-15 10:03:00');

        Artisan::call('schedule:run');

        $post->refresh();
        $this->assertSame('published', $post->status, 'The task must run once the overlap lock is released.');
    }

    public function test_on_one_server_lock_skips_second_run_of_the_same_minute(): void
    {
        $this->dueScheduledPost();

        $skipped = [];
        Event::listen(ScheduledTaskSkipped::class, function ($event) use (&$skipped): void {
            $skipped[] = $event->task->getSummaryForDisplay();
        });

        Artisan::call('schedule:run');

        $this->simulateFreshSchedulerProcess();

        Artisan::call('schedule:run');

        $this->assertContains('blog:publish-scheduled', $skipped, 'The one-server mutex must skip the duplicate run.');
        $this->assertSame(1, AuditLog::query()->where('action', 'post.published')->count());
    }

    public function test_analytics_aggregation_task_runs_with_preserved_cleanup_argument(): void
    {
        $event = $this->eventNamed($this->bootSchedule(), 'analytics:aggregate');

        $visitor = Visitor::create([
            'visitor_hash' => str_repeat('a', 64),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $category = Category::create([
            'name' => 'Utility Tools',
            'slug' => 'utility-tools',
            'icon' => 'tools',
            'short_description' => 'Utilities.',
            'display_order' => 99,
            'is_active' => true,
        ]);

        $tool = Tool::create([
            'category_id' => $category->id,
            'name' => 'Metadata Viewer',
            'slug' => 'metadata-viewer',
            'short_description' => 'Inspect image metadata.',
            'component_key' => 'image-metadata',
            'is_active' => true,
        ]);

        PageView::create(['visitor_id' => $visitor->id, 'page_url' => '/', 'page_type' => 'home', 'visited_at' => now()->subDay()]);
        PageView::create(['visitor_id' => $visitor->id, 'page_url' => '/tools', 'page_type' => 'tools_index', 'visited_at' => now()->subDay()]);
        PageView::create(['visitor_id' => $visitor->id, 'page_url' => '/old', 'page_type' => 'static_page', 'visited_at' => now()->subDays(150)]);

        ToolUsage::create(['tool_id' => $tool->id, 'visitor_id' => $visitor->id, 'success' => true, 'created_at' => now()->subDay()]);
        ToolUsage::create(['tool_id' => $tool->id, 'visitor_id' => $visitor->id, 'success' => false, 'created_at' => now()->subDay()]);
        ToolUsage::create(['tool_id' => $tool->id, 'visitor_id' => $visitor->id, 'success' => true, 'created_at' => now()->subDays(150)]);

        // Executes the exact scheduled callback, including the preserved
        // --cleanup argument, through the in-process path.
        $event->run($this->app);

        $summary = AnalyticsDaily::query()->where('date', now()->subDay()->toDateString())->first();

        $this->assertNotNull($summary);
        $this->assertSame(1, (int) $summary->visitors);
        $this->assertSame(2, (int) $summary->page_views);
        $this->assertSame(2, (int) $summary->tool_usage);
        $this->assertSame(1, (int) $summary->tool_success);
        $this->assertSame(1, (int) $summary->tool_failed);

        // --cleanup pruned the raw rows older than the 90 day retention window.
        $this->assertSame(2, PageView::query()->count());
        $this->assertSame(2, ToolUsage::query()->count());
    }

    public function test_history_cleanup_task_runs_through_the_scheduled_callback(): void
    {
        $event = $this->eventNamed($this->bootSchedule(), 'blog:cleanup-history');

        $prunable = AiGeneration::create(['topic' => 'Prunable generation', 'status' => 'failed']);
        $recent = AiGeneration::create(['topic' => 'Recent generation', 'status' => 'completed']);

        AiGeneration::query()->whereKey($prunable->id)->update(['created_at' => now()->subDays(200)]);

        // Runs the scheduled callback with no --days option: the command's
        // own default retention (site setting, fallback 90) applies.
        $event->run($this->app);

        $this->assertDatabaseMissing('ai_generations', ['topic' => 'Prunable generation']);
        $this->assertDatabaseHas('ai_generations', ['topic' => 'Recent generation']);
    }

    public function test_task_failures_are_reported_and_logged_not_swallowed(): void
    {
        $failed = [];
        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) use (&$failed): void {
            $failed[] = $event->task->getSummaryForDisplay();
        });

        Schedule::call(fn () => ScheduledArtisan::call('schedule-probe-failure'))
            ->name('scheduler-test-failure')
            ->everyMinute();

        // Simulate a command that exits non-zero (without throwing) while
        // every other scheduled command still executes for real.
        $realArtisan = Artisan::getFacadeRoot();
        Artisan::shouldReceive('call')->andReturnUsing(function (
            string $command,
            array $parameters = [],
            $output = null
        ) use ($realArtisan): int {
            return $command === 'schedule-probe-failure'
                ? 1
                : $realArtisan->call($command, $parameters, $output);
        });

        Log::spy();

        Artisan::call('schedule:run');

        $this->assertContains('scheduler-test-failure', $failed, 'A failing task must dispatch ScheduledTaskFailed.');

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'Scheduled Artisan command failed.')
                && $context['command'] === 'schedule-probe-failure'
                && $context['exit_code'] === 1;
        });
    }

    public function test_throwing_task_does_not_break_remaining_schedule(): void
    {
        $post = $this->dueScheduledPost();

        $failed = [];
        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) use (&$failed): void {
            $failed[] = $event->task->getSummaryForDisplay();
        });

        Schedule::call(fn () => throw new RuntimeException('scheduler-test-boom'))
            ->name('scheduler-test-throws')
            ->everyMinute();

        Artisan::call('schedule:run');

        $this->assertContains('scheduler-test-throws', $failed, 'A throwing task must dispatch ScheduledTaskFailed.');
        $post->refresh();
        $this->assertSame('published', $post->status, 'A throwing task must not prevent other due tasks from running.');
    }

    public function test_scheduler_completes_without_proc_open(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Cannot simulate the shared-hosting environment: proc_open is already unavailable here.');
        }

        $database = storage_path('framework/testing-scheduler-proc-open.sqlite');

        if (file_exists($database)) {
            @unlink($database);
        }

        try {
            touch($database);

            $env = [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $database,
                'DB_URL' => '',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
            ];

            $migrate = new Process(
                [PHP_BINARY, '-d', 'disable_functions=proc_open', base_path('artisan'), 'migrate', '--force', '--no-ansi'],
                base_path(),
                $env
            );
            $migrate->setTimeout(300);
            $migrate->run();

            $this->assertSame(
                0,
                $migrate->getExitCode(),
                'Migration failed: '.$migrate->getOutput().$migrate->getErrorOutput()
            );

            $scheduler = new Process(
                [PHP_BINARY, '-d', 'disable_functions=proc_open', base_path('artisan'), 'schedule:run', '--no-ansi'],
                base_path(),
                $env
            );
            $scheduler->setTimeout(300);
            $scheduler->run();

            $output = $scheduler->getOutput().$scheduler->getErrorOutput();

            $this->assertSame(0, $scheduler->getExitCode(), 'Scheduler failed without proc_open: '.$output);
            $this->assertStringContainsString('blog:publish-scheduled', $output, 'The every-minute task must have run.');
            $this->assertStringNotContainsString('proc_open', $output, 'No proc_open error may be raised by the scheduler.');
            $this->assertStringNotContainsString('RuntimeException', $output, 'No runtime failure may be raised by the scheduler.');
        } finally {
            if (file_exists($database)) {
                @unlink($database);
            }
        }
    }
}
