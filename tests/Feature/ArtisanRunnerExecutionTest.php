<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\ArtisanRunner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Execution tests for the Artisan Command Runner.
 *
 * - One genuine integration test runs `db:seed --class=DatabaseSeeder
 *   --force` through a real process against a disposable SQLite file —
 *   never against production data.
 * - Failure paths (non-zero exit, timeout, truncation, blocked host) use
 *   mocked execution. No destructive command is ever executed.
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
    // Genuine execution (disposable SQLite file, real process)
    // ------------------------------------------------------------------

    public function test_db_seed_runs_through_a_real_process(): void
    {
        $database = storage_path('framework/testing/artisan-runner-exec-test.sqlite');
        @mkdir(dirname($database), 0777, true);
        @unlink($database);

        // Symfony Process builds the child environment from $_ENV, which
        // takes precedence over putenv — patch $_ENV for the child only.
        $previousDatabase = $_ENV['DB_DATABASE'] ?? null;

        try {
            // Prepare the disposable database with a child process — the
            // parent test database connection is never touched.
            $migrate = new Process(
                [PHP_BINARY, base_path('artisan'), 'migrate', '--force'],
                base_path(),
                ['DB_DATABASE' => $database],
            );
            $migrate->setTimeout(120);
            $migrate->run();

            $this->assertTrue($migrate->isSuccessful(), 'Disposable schema preparation failed: '.$migrate->getErrorOutput());

            $_ENV['DB_DATABASE'] = realpath($database);

            $response = $this->actingAs($this->authorizedAdmin)
                ->postJson('/admin/artisan-runner/run', [
                    'command' => 'php artisan db:seed --class=DatabaseSeeder --force',
                ]);

            $response->assertOk();
            $result = $response->json('result');

            $this->assertSame('success', $result['status'], 'exit='.var_export($result['exit_code'] ?? null, true).' | stdout: '.($result['stdout'] ?? '').' | stderr: '.($result['stderr'] ?? ''));
            $this->assertSame(0, $result['exit_code']);
            $this->assertStringContainsString('Seeding database', $result['stdout']);

            $this->assertDatabaseHas('audit_logs', ['action' => 'artisan_runner.success']);
        } finally {
            if ($previousDatabase !== null) {
                $_ENV['DB_DATABASE'] = $previousDatabase;
            } else {
                unset($_ENV['DB_DATABASE']);
            }

            @unlink($database);
        }
    }

    public function test_rejected_commands_are_audited_without_their_raw_input(): void
    {
        $response = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', [
                'command' => 'migrate:fresh --force && echo INTERNAL-SERVER-DETAIL-MARKER',
            ]);

        $response->assertOk();
        $this->assertSame('rejected', $response->json('result.status'));

        $entry = AuditLog::query()->where('action', 'artisan_runner.rejected')->first();
        $this->assertNotNull($entry);
        $this->assertStringNotContainsString('INTERNAL-SERVER-DETAIL-MARKER', json_encode($entry->properties) ?: '');
        $this->assertStringNotContainsString('migrate:fresh', json_encode($entry->properties) ?: '');
    }

    // ------------------------------------------------------------------
    // Mocked execution failure paths
    // ------------------------------------------------------------------

    protected function bindRunner(array $result): void
    {
        $this->app->instance(ArtisanRunner::class, new class($result) extends ArtisanRunner
        {
            public function __construct(private readonly array $result) {}

            public function run(string $input): array
            {
                $result = $this->result;
                $result['command'] = $input;

                return $result;
            }
        });
    }

    public function test_non_zero_exit_code_is_reported_as_failure(): void
    {
        $this->bindRunner([
            'ok' => false, 'status' => 'failed', 'message' => 'Command finished with a non-zero exit code — see the output below.',
            'exit_code' => 1, 'duration_ms' => 40, 'stdout' => 'Seeding failed output', 'stderr' => '', 'output_truncated' => false,
        ]);

        $response = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'db:seed --class=DatabaseSeeder --force']);

        $response->assertOk();
        $result = $response->json('result');

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame('failed', $result['status']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'artisan_runner.failed']);
    }

    public function test_timeout_is_reported_clearly(): void
    {
        $this->bindRunner([
            'ok' => false, 'status' => 'timeout', 'message' => 'The command was terminated after 120 seconds.',
            'exit_code' => null, 'duration_ms' => 120000, 'stdout' => '', 'stderr' => '', 'output_truncated' => false,
        ]);

        $response = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'db:seed --force']);

        $result = $response->json('result');
        $this->assertSame('timeout', $result['status']);
        $this->assertStringContainsString('terminated after 120 seconds', $result['message']);
    }

    public function test_oversized_output_is_truncated(): void
    {
        $this->bindRunner([
            'ok' => true, 'status' => 'success', 'message' => 'Command completed successfully.',
            'exit_code' => 0, 'duration_ms' => 10,
            'stdout' => str_repeat('x', ArtisanRunner::MAX_OUTPUT_BYTES + 500),
            'stderr' => '', 'output_truncated' => true,
        ]);

        $result = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'route:list'])
            ->json('result');

        $this->assertTrue($result['output_truncated']);
        $this->assertStringContainsString(str_repeat('x', 200), $result['stdout']);
    }

    public function test_unsupported_hosting_is_reported_without_unsafe_fallback(): void
    {
        $runner = new class extends ArtisanRunner
        {
            public function __construct()
            {
                // Bypass the constructor capability probe; simulate a host
                // with proc_open disabled.
            }

            public function environmentProblem(): ?string
            {
                return 'The proc_open() PHP function is disabled on this server, so commands cannot be started safely.';
            }
        };

        $this->app->instance(ArtisanRunner::class, $runner);

        $result = $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'db:seed --force'])
            ->json('result');

        $this->assertSame('blocked', $result['status']);
        $this->assertStringContainsString('proc_open', $result['message']);
        $this->assertStringNotContainsString('shell', strtolower($result['message']));
    }

    public function test_command_input_length_is_limited(): void
    {
        $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => str_repeat('a', ArtisanRunner::MAX_INPUT_LENGTH + 1)])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Real Process plumbing sanity (non-Artisan, harmless)
    // ------------------------------------------------------------------

    public function test_symfony_process_is_usable_with_argument_arrays(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'echo 1 + 1;']);
        $process->run();

        $this->assertTrue($process->isSuccessful());
        $this->assertSame('2', trim($process->getOutput()));
    }
}
