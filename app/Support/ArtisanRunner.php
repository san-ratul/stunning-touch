<?php

namespace App\Support;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Secure, admin-only Artisan Command Runner engine.
 *
 * Security model:
 * - Strict server-side allowlist of commands with per-command argument
 *   schemas. Anything not explicitly allowed is rejected.
 * - Commands execute IN-PROCESS through Laravel's native console kernel
 *   (Artisan::call with a BufferedOutput) — no shell, no external process
 *   functions, no PHP CLI spawning. This keeps the runner operational on
 *   shared hosting that disables process-spawning PHP functions.
 * - Output is size-capped; raw input never reaches any shell or eval.
 * - A cache lock prevents concurrent executions inside the same request
 *   worker; the web request itself remains bounded by the hosting PHP
 *   execution limits (in-process execution cannot be force-terminated).
 * - If a specific command cannot run in the current environment, its
 *   failure is reported without affecting the other commands.
 *
 * Authorization is NOT part of this class: callers must enforce the
 * artisan-runner.access gate before touching it.
 */
class ArtisanRunner
{
    /** Lock TTL: bounds how long a busy lock is honored. */
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
        $command = array_shift($argv);
        $parameters = $this->argvToParameters($argv);

        // Prevent concurrent executions on the same request worker.
        $lock = null;

        try {
            $lock = Cache::lock('artisan-runner.execution', self::TIMEOUT_SECONDS);
            $acquired = $lock->get();
        } catch (\Throwable) {
            // If the cache store cannot provide locks, proceed without one:
            // concurrency protection is a nicety, not a security control.
            $lock = null;
            $acquired = true;
        }

        if (! $acquired) {
            return [
                'ok' => false,
                'status' => 'busy',
                'message' => 'Another command is currently running. Wait for it to finish and try again.',
                'exit_code' => null,
                'duration_ms' => 0,
                'stdout' => '',
                'stderr' => '',
                'output_truncated' => false,
            ];
        }

        $started = microtime(true);
        $output = new BufferedOutput;

        try {
            $exitCode = $this->runArtisan($command, $parameters, $output);
        } catch (\Throwable $exception) {
            $this->logFailure($exception);

            // Never forward raw exception details to the browser. A few
            // commands internally need process spawning (e.g. `about`
            // reads the composer version); report that per-command without
            // disabling the other approved commands.
            $raw = $output->fetch();
            $message = $exception instanceof CommandNotFoundException
                ? 'This command is not registered in the application console.'
                : (str_contains($exception->getMessage(), 'proc_open')
                    ? 'This command cannot run on this server: it requires process spawning (proc_open), which is disabled by the hosting provider. Other approved commands still work.'
                    : 'The command failed with an unexpected error. Details were logged for the administrator.');

            return [
                'ok' => false,
                'status' => 'failed',
                'message' => $message,
                'exit_code' => 1,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'stdout' => $this->truncate($raw),
                'stderr' => '',
                'output_truncated' => strlen($raw) > self::MAX_OUTPUT_BYTES,
            ];
        } finally {
            if ($lock !== null) {
                optional($lock)->release();
            }
        }

        $raw = $output->fetch();
        $successful = $exitCode === 0;

        return [
            'ok' => $successful,
            'status' => $successful ? 'success' : 'failed',
            'message' => $successful
                ? 'Command completed successfully.'
                : 'Command finished with a non-zero exit code — see the output below.',
            'exit_code' => $exitCode,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'stdout' => $this->truncate($raw),
            'stderr' => '',
            'output_truncated' => strlen($raw) > self::MAX_OUTPUT_BYTES,
        ];
    }

    /**
     * Run the registered Artisan command in-process. Overridden in tests
     * to simulate command failures without executing anything destructive.
     *
     * Note: some console commands mutate the shared router's middleware
     * registry (e.g. route:list flushes middleware groups to filter the
     * display). Because we run inside a live HTTP request, the registry
     * is snapshotted and restored so the request lifecycle stays intact.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function runArtisan(string $command, array $parameters, BufferedOutput $output): int
    {
        $router = app('router');
        $aliases = $router->getMiddleware();
        $groups = $router->getMiddlewareGroups();

        $exitCode = Artisan::call($command, $parameters, $output);

        $this->restoreMiddlewareRegistry($router, $aliases, $groups);

        return $exitCode;
    }

    /**
     * Restore the router middleware registry if a command altered it.
     *
     * @param  Router  $router
     * @param  array<string, mixed>  $aliases
     * @param  array<string, list<string>>  $groups
     */
    protected function restoreMiddlewareRegistry($router, array $aliases, array $groups): void
    {
        if ($router->getMiddleware() !== $aliases) {
            foreach ($aliases as $name => $target) {
                $router->aliasMiddleware($name, $target);
            }
        }

        if ($router->getMiddlewareGroups() !== $groups) {
            $router->flushMiddlewareGroups();

            foreach ($groups as $name => $classes) {
                $router->middlewareGroup($name, $classes);
            }
        }
    }

    /**
     * Convert schema-validated argv pieces to Artisan call parameters.
     *
     * @param  list<string>  $argv
     * @return array<string, mixed>
     */
    protected function argvToParameters(array $argv): array
    {
        $parameters = [];

        foreach ($argv as $piece) {
            if (str_starts_with($piece, '--')) {
                $body = substr($piece, 2);

                if (str_contains($body, '=')) {
                    [$name, $value] = explode('=', $body, 2);
                    $parameters['--'.$name] = $value;
                } else {
                    $parameters['--'.$body] = true;
                }
            }
        }

        return $parameters;
    }

    /**
     * Diagnose the hosting environment for the runner UI.
     *
     * In-process execution runs inside the already-bootstrapped Laravel
     * application and has no external-process requirements, so this
     * reports no problem. Individual command failures (if a hosting
     * capability a specific command needs is unavailable) are reported
     * per-command in the execution result without disabling the runner.
     */
    public function environmentProblem(): ?string
    {
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

    protected function logFailure(\Throwable $exception): void
    {
        logger()->warning('Artisan runner command raised an exception', [
            'detail' => $exception->getMessage(),
            'class' => $exception::class,
        ]);
    }
}
