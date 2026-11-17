<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\ArtisanRunner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Exception\LogicException;
use Tests\TestCase;

/**
 * In-process execution tests for the Artisan Command Runner.
 *
 * Commands run through Laravel's native console kernel inside the current
 * request process — no proc_open(), no Symfony Process, no PHP CLI
 * spawning. All execution targets the disposable test database; nothing
 * touches production data.
 */
class ArtisanRunnerExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected User $authorizedAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->authorizedAdmin = User::create([
            'name' => 'Authorized Admin',
            'email' => 'authorized@example.com',
            'password' => 'password-123456',
            'is_active' => true,
            'artisan_runner_access' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // In-process execution — genuine commands against the test database
    // ------------------------------------------------------------------

    public function test_db_seed_runs_in_process_and_seeds_the_database(): void
    {
        $response = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', [
                'command' => 'php artisan db:seed --class=DatabaseSeeder --force',
            ]);

        $response->assertOk();
        $result = $response->json('result');

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertStringContainsString('Seeding database', $result['stdout']);
        $this->assertFalse($result['output_truncated']);

        // The tools are still present after seeding (idempotent run).
        $this->assertDatabaseHas('tools', ['slug' => 'regex-tester']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'artisan_runner.success']);
    }

    public function test_cache_clear_runs_in_process(): void
    {
        $result = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'cache:clear'])
            ->json('result');

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'artisan_runner.success']);
    }

    public function test_about_command_runs_in_process(): void
    {
        $result = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'about'])
            ->json('result');

        $this->assertSame('success', $result['status']);
        $this->assertNotSame('', $result['stdout']);
    }

    public function test_route_list_runs_in_process(): void
    {
        $result = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'route:list'])
            ->json('result');

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('artisan-runner', $result['stdout']);
    }

    public function test_optimize_clear_runs_in_process(): void
    {
        $result = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'optimize:clear'])
            ->json('result');

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['exit_code']);
    }

    // ------------------------------------------------------------------
    // Implementation guarantees: no Symfony Process, no proc_open
    // ------------------------------------------------------------------

    public function test_runner_source_has_no_process_spawning_or_shell_mechanisms(): void
    {
        $source = file_get_contents((new ArtisanRunnerReflection)->sourcePath());

        foreach ([
            'Symfony\\Component\\Process',
            'proc_open(',
            'shell_exec',
            'system(',
            'passthru(',
            'eval(',
            'exec(',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "The runner must not depend on {$forbidden}.");
        }
    }

    public function test_environment_diagnostics_no_longer_depend_on_proc_open(): void
    {
        $this->app->instance(ArtisanRunner::class, new ArtisanRunner);

        // With proc_open disabled (simulated hosting), the runner page and
        // execution must still be available — in-process execution does
        // not need it.
        $response = $this->actingAs($this->authorizedAdmin)->get('/admin/artisan-runner');

        $response->assertOk();
        $this->assertStringNotContainsString('proc_open', $response->getContent());
    }

    public function test_runner_works_when_proc_open_is_absent(): void
    {
        // The whole execution path must be usable regardless of the host's
        // proc_open availability. environmentProblem() must stay silent.
        $runner = new ArtisanRunner;

        $this->assertNull($runner->environmentProblem());
        $this->assertSame('success', $runner->run('cache:clear')['status']);
    }

    // ------------------------------------------------------------------
    // Failure paths
    // ------------------------------------------------------------------

    public function test_command_exception_is_sanitized_without_detail_leakage(): void
    {
        $runner = new class extends ArtisanRunner
        {
            protected function runArtisan(string $command, array $parameters, BufferedOutput $output): int
            {
                throw new \RuntimeException('SECRET-EXCEPTION-DETAIL: pdo connection string with password');
            }
        };

        $this->app->instance(ArtisanRunner::class, $runner);

        $response = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'db:seed --class=DatabaseSeeder --force']);

        $response->assertOk();
        $result = $response->json('result');

        $this->assertSame('failed', $result['status']);
        $this->assertSame(1, $result['exit_code']);
        $this->assertStringContainsString('unexpected error', $result['message']);

        $content = $response->getContent();
        $this->assertStringNotContainsString('SECRET-EXCEPTION-DETAIL', $content);
        $this->assertStringNotContainsString('pdo connection string', $content);
    }

    public function test_command_not_found_is_reported_sanitized(): void
    {
        $runner = new class extends ArtisanRunner
        {
            protected function runArtisan(string $command, array $parameters, BufferedOutput $output): int
            {
                throw new CommandNotFoundException($command);
            }
        };

        $this->app->instance(ArtisanRunner::class, $runner);

        $result = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'db:seed --force'])
            ->json('result');

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('not registered', $result['message']);
    }

    public function test_commands_requiring_process_spawning_report_a_command_specific_limitation(): void
    {
        $runner = new class extends ArtisanRunner
        {
            protected function runArtisan(string $command, array $parameters, BufferedOutput $output): int
            {
                throw new LogicException('The Process class relies on proc_open, which is not available on your PHP installation.');
            }
        };

        $this->app->instance(ArtisanRunner::class, $runner);

        $result = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'about'])
            ->json('result');

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('requires process spawning', $result['message']);
        $this->assertStringContainsString('Other approved commands still work', $result['message']);
    }

    public function test_non_zero_exit_code_is_reported_as_failure(): void
    {
        $runner = new class extends ArtisanRunner
        {
            protected function runArtisan(string $command, array $parameters, BufferedOutput $output): int
            {
                $output->writeln('Seeding failed output');

                return 1;
            }
        };

        $this->app->instance(ArtisanRunner::class, $runner);

        $response = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'db:seed --class=DatabaseSeeder --force']);

        $result = $response->json('result');

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Seeding failed output', $result['stdout']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'artisan_runner.failed']);
    }

    public function test_oversized_output_is_truncated(): void
    {
        $runner = new class extends ArtisanRunner
        {
            protected function runArtisan(string $command, array $parameters, BufferedOutput $output): int
            {
                $output->write(str_repeat('x', ArtisanRunner::MAX_OUTPUT_BYTES + 500));

                return 0;
            }
        };

        $this->app->instance(ArtisanRunner::class, $runner);

        $result = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'route:list'])
            ->json('result');

        $this->assertTrue($result['output_truncated']);
        $this->assertStringContainsString('[output truncated', $result['stdout']);
        $this->assertStringContainsString(str_repeat('x', 200), $result['stdout']);
    }

    public function test_command_input_length_is_limited(): void
    {
        $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => str_repeat('a', ArtisanRunner::MAX_INPUT_LENGTH + 1)])
            ->assertStatus(422);
    }

    public function test_busy_lock_prevents_concurrent_execution(): void
    {
        $lock = Cache::lock('artisan-runner.execution', ArtisanRunner::TIMEOUT_SECONDS);
        $lock->get(); // simulate another execution in progress

        try {
            $result = $this->actingAs($this->authorizedAdmin)
                ->postJson('/admin/artisan-runner/run', ['command' => 'about'])
                ->json('result');

            $this->assertSame('busy', $result['status']);
            $this->assertStringContainsString('currently running', $result['message']);
        } finally {
            $lock->release();
        }
    }

    // ------------------------------------------------------------------
    // Fail-closed locking: the executor must never run without its lock
    // ------------------------------------------------------------------

    protected function bindRecordingRunner(): object
    {
        $runner = new class extends ArtisanRunner
        {
            public bool $invoked = false;

            protected function runArtisan(string $command, array $parameters, BufferedOutput $output): int
            {
                $this->invoked = true;

                return 0;
            }
        };

        $this->app->instance(ArtisanRunner::class, $runner);

        return $runner;
    }

    public function test_unsupported_lock_store_prevents_execution(): void
    {
        $runner = $this->bindRecordingRunner();

        // Simulate a cache store without lock support (Repository::lock
        // throws BadMethodCallException in that case).
        Cache::shouldReceive('lock')
            ->once()
            ->andThrow(new \BadMethodCallException('This cache store does not support locks.'));

        $result = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'db:seed --class=DatabaseSeeder --force'])
            ->json('result');

        $this->assertSame('blocked', $result['status']);
        $this->assertNull($result['exit_code']);
        $this->assertStringContainsString('atomic execution lock', $result['message']);
        $this->assertStringContainsString('cache driver does not support locks', $result['message']);
        $this->assertFalse($runner->invoked, 'The executor must never run without an acquired lock.');
    }

    public function test_lock_acquisition_failure_prevents_execution(): void
    {
        $runner = $this->bindRecordingRunner();

        // Lock provider exists but fails to hand out the lock.
        Cache::shouldReceive('lock')
            ->once()
            ->andThrow(new LockTimeoutException('Could not acquire the lock.'));

        $result = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'db:seed --class=DatabaseSeeder --force'])
            ->json('result');

        $this->assertSame('blocked', $result['status']);
        $this->assertStringContainsString('atomic execution lock', $result['message']);
        $this->assertStringContainsString('could not be acquired', $result['message']);
        $this->assertFalse($runner->invoked, 'The executor must never run without an acquired lock.');
    }

    public function test_unexpected_lock_error_prevents_execution(): void
    {
        $runner = $this->bindRecordingRunner();

        Cache::shouldReceive('lock')
            ->once()
            ->andThrow(new \RuntimeException('connection refused'));

        $result = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'db:seed --class=DatabaseSeeder --force'])
            ->json('result');

        $this->assertSame('blocked', $result['status']);
        $this->assertStringContainsString('could not be acquired', $result['message']);
        // The raw error must never reach the browser.
        $this->assertStringNotContainsString('connection refused', json_encode($result));
        $this->assertFalse($runner->invoked, 'The executor must never run without an acquired lock.');
    }

    public function test_lock_is_released_after_a_successful_run(): void
    {
        $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'cache:clear'])
            ->json('result');

        // The lock must be free again after execution.
        $lock = Cache::lock('artisan-runner.execution', ArtisanRunner::TIMEOUT_SECONDS);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    // ------------------------------------------------------------------
    // Audit trail
    // ------------------------------------------------------------------

    public function test_rejected_commands_are_audited_without_their_raw_input(): void
    {
        $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', [
                'command' => 'migrate:fresh --force && echo INTERNAL-SERVER-DETAIL-MARKER',
            ]);

        $entry = AuditLog::query()->where('action', 'artisan_runner.rejected')->first();
        $this->assertNotNull($entry);
        $this->assertStringNotContainsString('INTERNAL-SERVER-DETAIL-MARKER', json_encode($entry->properties) ?: '');
        $this->assertStringNotContainsString('migrate:fresh', json_encode($entry->properties) ?: '');
    }
}

/**
 * Test helper exposing the runner's own source path for implementation
 * guarantee assertions.
 */
final class ArtisanRunnerReflection
{
    public function sourcePath(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'ArtisanRunner.php';
    }
}
