<?php

namespace Tests\Unit;

use App\Support\ScheduledArtisan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ScheduledArtisanTest extends TestCase
{
    public function test_successful_command_runs_in_process_and_returns_exit_code(): void
    {
        Artisan::shouldReceive('call')->once()->andReturnUsing(function (
            string $command,
            array $parameters,
            BufferedOutput $output
        ): int {
            $this->assertSame('analytics:aggregate', $command);
            $this->assertSame(['--cleanup' => true], $parameters);
            $output->writeln('Aggregated 2026-06-14: 3 views.');

            return 0;
        });

        $exitCode = ScheduledArtisan::call('analytics:aggregate', ['--cleanup' => true]);

        $this->assertSame(0, $exitCode);
    }

    public function test_non_zero_exit_code_logs_output_and_throws(): void
    {
        Artisan::shouldReceive('call')->once()->andReturnUsing(function (
            string $command,
            array $parameters,
            BufferedOutput $output
        ): int {
            $output->writeln('Something went wrong during aggregation.');

            return 1;
        });

        Log::spy();

        try {
            ScheduledArtisan::call('analytics:aggregate', ['--cleanup' => true]);
            $this->fail('Expected a RuntimeException for a non-zero exit code.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('analytics:aggregate', $exception->getMessage());
            $this->assertStringContainsString('exit code [1]', $exception->getMessage());
            $this->assertStringContainsString('Something went wrong during aggregation.', $exception->getMessage());
        }

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'Scheduled Artisan command failed.')
                && $context['command'] === 'analytics:aggregate'
                && $context['exit_code'] === 1
                && str_contains($context['output'], 'Something went wrong during aggregation.');
        });
    }

    public function test_command_exceptions_are_never_swallowed(): void
    {
        Artisan::shouldReceive('call')->once()->andThrow(
            new CommandNotFoundException('There are no commands defined in the "missing" namespace.')
        );

        $this->expectException(CommandNotFoundException::class);

        ScheduledArtisan::call('missing:command');
    }
}
