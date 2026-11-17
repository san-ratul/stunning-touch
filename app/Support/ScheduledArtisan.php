<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * In-process Artisan runner for scheduled tasks.
 *
 * Laravel's scheduler normally executes `Schedule::command()` events by
 * spawning a `php artisan ...` subprocess through Symfony Process, which
 * requires proc_open(). Shared hosting frequently disables that function,
 * so every scheduled task would fail there.
 *
 * This helper executes an Artisan command inside the already-bootstrapped
 * application process instead: no subprocess is spawned, no shell is
 * involved, and no proc_open() is needed. Console output is captured with
 * a BufferedOutput; when a command exits non-zero the captured output is
 * logged and a RuntimeException is thrown so the scheduler reports the
 * task as failed (ScheduledTaskFailed + exception handler report).
 */
class ScheduledArtisan
{
    /**
     * Run an Artisan command in-process for the scheduler.
     *
     * @param  string  $command  Command name, e.g. 'analytics:aggregate'
     * @param  array<string, mixed>  $parameters  Command options/arguments
     * @return int The command exit code (0 on success)
     *
     * @throws RuntimeException When the command exits with a non-zero code
     * @throws Throwable When the command itself throws
     */
    public static function call(string $command, array $parameters = []): int
    {
        $output = new BufferedOutput;

        $exitCode = Artisan::call($command, $parameters, $output);
        $consoleOutput = trim($output->fetch());

        if ($exitCode !== 0) {
            Log::error('Scheduled Artisan command failed.', [
                'command' => $command,
                'parameters' => $parameters,
                'exit_code' => $exitCode,
                'output' => $consoleOutput,
            ]);

            throw new RuntimeException(sprintf(
                'Scheduled Artisan command [%s] failed with exit code [%d].%s',
                $command,
                $exitCode,
                $consoleOutput !== '' ? ' Output: '.$consoleOutput : ''
            ));
        }

        return $exitCode;
    }
}
