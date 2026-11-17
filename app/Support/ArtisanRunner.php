<?php

namespace App\Support;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Secure, admin-only Artisan Command Runner engine.
 *
 * Security model:
 * - Strict server-side allowlist of commands with per-command argument
 *   schemas. Anything not explicitly allowed is rejected.
 * - No shell is ever involved: the command is executed as a process array
 *   [PHP_BINARY, <absolute artisan path>, name, options...] with
 *   Symfony Process and an explicit working directory.
 * - A hard timeout terminates the process; output is size-capped; raw
 *   input never reaches any shell or eval.
 * - If the hosting environment cannot safely spawn processes (e.g.
 *   proc_open disabled), environmentProblem() reports the exact missing
 *   capability and execution refuses to run — there is no unsafe fallback.
 *
 * Authorization is NOT part of this class: callers must enforce the
 * artisan-runner.access gate before touching it.
 */
class ArtisanRunner
{
    public const TIMEOUT_SECONDS = 120;

    public const MAX_OUTPUT_BYTES = 102400;

    public const MAX_INPUT_LENGTH = 500;

    /** Seeder classes that --class may reference. */
    public const ALLOWED_SEEDERS = ['DatabaseSeeder'];

    /**
     * Allowed commands. Option schemas: option name => null for valueless
     * flags, or a value type ('seeder' = whitelisted seeder class).
     *
     * @var array<string, array{description: string, options: array<string, ?string>}>
     */
    public const COMMANDS = [
        'db:seed' => [
            'description' => 'Run database seeders (only explicitly approved seeder classes).',
            'options' => ['class' => 'seeder', 'force' => null],
        ],
        'optimize:clear' => [
            'description' => 'Clear all cached config, routes, views and compiled files.',
            'options' => [],
        ],
        'cache:clear' => [
            'description' => 'Flush the application cache.',
            'options' => [],
        ],
        'config:clear' => [
            'description' => 'Remove the cached configuration file.',
            'options' => [],
        ],
        'route:clear' => [
            'description' => 'Remove the cached route file.',
            'options' => [],
        ],
        'view:clear' => [
            'description' => 'Clear all compiled Blade views.',
            'options' => [],
        ],
        'about' => [
            'description' => 'Show basic environment and configuration overview.',
            'options' => [],
        ],
        'route:list' => [
            'description' => 'List all registered routes.',
            'options' => [],
        ],
        'storage:link' => [
            'description' => 'Create the public/storage symbolic link (idempotent).',
            'options' => [],
        ],
    ];

    /** Shell metacharacters and control characters that must never appear. */
    private const FORBIDDEN_PATTERN = '/[\x00-\x1F\x7F;|&<>`$]/';

    /**
     * Parse and validate a submitted command line.
     *
     * @return array{argv: list<string>, display: string}
     *
     * @throws ArtisanCommandException
     */
    public function parse(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            throw new ArtisanCommandException('empty', 'Enter a command to run.');
        }

        if (strlen($input) > self::MAX_INPUT_LENGTH) {
            throw new ArtisanCommandException('too_long', 'The command is too long (maximum '.self::MAX_INPUT_LENGTH.' characters).');
        }

        if (preg_match(self::FORBIDDEN_PATTERN, $input) === 1) {
            throw new ArtisanCommandException('injection', 'The command contains characters that are not allowed (shell operators, newlines or control characters).');
        }

        $tokens = $this->tokenize($input);
        $tokens = $this->stripPrefix($tokens);

        if ($tokens === []) {
            throw new ArtisanCommandException('empty', 'Enter an Artisan command name, for example: db:seed --class=DatabaseSeeder --force');
        }

        $command = array_shift($tokens);

        if (! array_key_exists($command, self::COMMANDS)) {
            throw new ArtisanCommandException('unknown_command', 'This command is not in the approved list. Only the commands shown under “Allowed commands” can be run.');
        }

        $schema = self::COMMANDS[$command]['options'];
        $options = [];

        while ($tokens !== []) {
            $token = array_shift($tokens);

            if (! str_starts_with($token, '--')) {
                throw new ArtisanCommandException('unexpected_argument', 'Unexpected argument: only the documented options are allowed.');
            }

            $body = substr($token, 2);
            $name = $body;
            $value = null;

            if (str_contains($body, '=')) {
                [$name, $value] = explode('=', $body, 2);
            }

            if (! array_key_exists($name, $schema)) {
                throw new ArtisanCommandException('unknown_option', "Option --{$name} is not allowed for {$command}.");
            }

            if ($schema[$name] === null) {
                if ($value !== null) {
                    throw new ArtisanCommandException('bad_value', "Option --{$name} does not take a value.");
                }

                $options[$name] = true;

                continue;
            }

            if ($value === null && $tokens !== [] && ! str_starts_with($tokens[0], '--')) {
                $value = array_shift($tokens);
            }

            if ($value === null || trim($value) === '') {
                throw new ArtisanCommandException('bad_value', "Option --{$name} requires a value.");
            }

            $options[$name] = $this->validateValue($name, $schema[$name], $value);
        }

        $argv = [$command];

        if (isset($options['class'])) {
            $argv[] = '--class='.$options['class'];
        }

        if (! empty($options['force'])) {
            $argv[] = '--force';
        }

        return [
            'argv' => $argv,
            'display' => implode(' ', $argv),
        ];
    }

    /**
     * Parse, validate and execute. Returns a structured result.
     *
     * @return array<string, mixed>
     */
    public function run(string $input): array
    {
        $parsed = $this->parse($input);
        $result = $this->execute($parsed['argv']);
        $result['command'] = $parsed['display'];

        return $result;
    }

    /**
     * Execute the validated argv. Overridden in tests to mock execution.
     *
     * @param  list<string>  $argv
     * @return array<string, mixed>
     */
    protected function execute(array $argv): array
    {
        $problem = $this->environmentProblem();

        if ($problem !== null) {
            return $this->blockedResult($problem);
        }

        $process = new Process(
            [PHP_BINARY, base_path('artisan'), ...$argv],
            base_path(),
            null,
            null,
            self::TIMEOUT_SECONDS,
        );

        $started = microtime(true);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return [
                'ok' => false,
                'status' => 'timeout',
                'message' => 'The command was terminated after '.self::TIMEOUT_SECONDS.' seconds.',
                'exit_code' => null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'stdout' => $this->truncate($process->getOutput()),
                'stderr' => '',
                'output_truncated' => strlen($process->getOutput()) > self::MAX_OUTPUT_BYTES,
            ];
        } catch (\Throwable $exception) {
            $this->logFailure($exception);

            return $this->blockedResult('The command could not be started. '.static::capabilityHint());
        }

        return [
            'ok' => $process->isSuccessful(),
            'status' => $process->isSuccessful() ? 'success' : 'failed',
            'message' => $process->isSuccessful()
                ? 'Command completed successfully.'
                : 'Command finished with a non-zero exit code — see the output below.',
            'exit_code' => $process->getExitCode(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'stdout' => $this->truncate($process->getOutput()),
            'stderr' => $this->truncate($process->getErrorOutput()),
            'output_truncated' => strlen($process->getOutput().$process->getErrorOutput()) > self::MAX_OUTPUT_BYTES,
        ];
    }

    /**
     * Diagnose the hosting environment. Returns null when safe process
     * execution is available, or an administrator-facing explanation of
     * the exact missing capability.
     */
    public function environmentProblem(): ?string
    {
        if (! function_exists('proc_open')) {
            return 'The proc_open() PHP function is disabled on this server, so commands cannot be started safely. This is a hosting restriction — contact the hosting provider or run commands manually.';
        }

        if (PHP_BINARY === '') {
            return 'The PHP CLI binary path is not available, so Artisan cannot be executed.';
        }

        if (! is_file(base_path('artisan'))) {
            return 'The Artisan entry point (artisan) was not found in the project root.';
        }

        try {
            $probe = new Process([PHP_BINARY, '-v'], base_path(), null, null, 10);
            $probe->run();
        } catch (\Throwable $exception) {
            return 'PHP process execution is not available on this server ('.static::capabilityHint().').';
        }

        if (! $probe->isSuccessful()) {
            return 'The PHP CLI binary could not be executed on this server.';
        }

        return null;
    }

    /**
     * Catalog for the UI's allowed-commands reference.
     *
     * @return list<array{name: string, description: string, options: list<string>}>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::COMMANDS as $name => $definition) {
            $options = [];

            foreach ($definition['options'] as $option => $type) {
                $options[] = $type === 'seeder'
                    ? '--class=<seeder> (approved seeders only: '.implode(', ', self::ALLOWED_SEEDERS).')'
                    : "--{$option}";
            }

            $catalog[] = [
                'name' => $name,
                'description' => $definition['description'],
                'options' => $options,
            ];
        }

        return $catalog;
    }

    protected function validateValue(string $option, string $type, string $value): string
    {
        if ($type === 'seeder') {
            $normalized = str_replace(['/', '\\'], '', trim($value));

            if (! in_array($normalized, self::ALLOWED_SEEDERS, true)) {
                throw new ArtisanCommandException('bad_value', "The seeder class {$value} is not approved. Approved seeders: ".implode(', ', self::ALLOWED_SEEDERS).'.');
            }

            return $normalized;
        }

        throw new ArtisanCommandException('bad_value', "Unsupported option value type for --{$option}.");
    }

    /**
     * @return list<string>
     */
    protected function stripPrefix(array $tokens): array
    {
        if ((count($tokens) >= 2) && $tokens[0] === 'php' && $tokens[1] === 'artisan') {
            return array_slice($tokens, 2);
        }

        if ($tokens[0] === 'artisan' && count($tokens) >= 2) {
            return array_slice($tokens, 1);
        }

        return $tokens;
    }

    /**
     * Whitespace-aware tokenizer supporting double-quoted values. Quotes
     * themselves are removed; an unbalanced quote is rejected.
     *
     * @return list<string>
     */
    protected function tokenize(string $input): array
    {
        $tokens = [];
        $current = '';
        $inQuotes = false;
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];

            if ($char === '"') {
                $inQuotes = ! $inQuotes;

                continue;
            }

            if ($char === ' ' && ! $inQuotes) {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }

                continue;
            }

            $current .= $char;
        }

        if ($inQuotes) {
            throw new ArtisanCommandException('injection', 'The command contains an unbalanced quote.');
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    protected function truncate(string $output): string
    {
        if (strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return $output;
        }

        return substr($output, 0, self::MAX_OUTPUT_BYTES)."\n… [output truncated at ".self::MAX_OUTPUT_BYTES.' bytes]';
    }

    protected function blockedResult(string $message): array
    {
        return [
            'ok' => false,
            'status' => 'blocked',
            'message' => $message,
            'exit_code' => null,
            'duration_ms' => 0,
            'stdout' => '',
            'stderr' => '',
            'output_truncated' => false,
        ];
    }

    protected static function capabilityHint(): string
    {
        return 'this usually means proc_open is disabled or the PHP CLI is restricted by the hosting provider';
    }

    protected function logFailure(\Throwable $exception): void
    {
        logger()->warning('Artisan runner could not start a process', [
            'detail' => $exception->getMessage(),
        ]);
    }
}
