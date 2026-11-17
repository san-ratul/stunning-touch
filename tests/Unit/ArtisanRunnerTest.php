<?php

namespace Tests\Unit;

use App\Support\ArtisanCommandException;
use App\Support\ArtisanRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ArtisanRunnerTest extends TestCase
{
    private ArtisanRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new ArtisanRunner;
    }

    public function test_accepts_bare_command_name(): void
    {
        $parsed = $this->runner->parse('db:seed --class=DatabaseSeeder --force');

        $this->assertSame(['db:seed', '--class=DatabaseSeeder', '--force'], $parsed['argv']);
        $this->assertSame('db:seed --class=DatabaseSeeder --force', $parsed['display']);
    }

    public function test_accepts_artisan_and_php_artisan_prefixes(): void
    {
        $this->assertSame(
            $this->runner->parse('db:seed --force')['argv'],
            $this->runner->parse('artisan db:seed --force')['argv'],
        );

        $this->assertSame(
            $this->runner->parse('db:seed --force')['argv'],
            $this->runner->parse('php artisan db:seed --force')['argv'],
        );
    }

    public function test_accepts_space_separated_option_value(): void
    {
        $parsed = $this->runner->parse('db:seed --class DatabaseSeeder');

        $this->assertSame(['db:seed', '--class=DatabaseSeeder'], $parsed['argv']);
    }

    public function test_accepts_quoted_option_value(): void
    {
        $parsed = $this->runner->parse('db:seed --class="DatabaseSeeder" --force');

        $this->assertSame(['db:seed', '--class=DatabaseSeeder', '--force'], $parsed['argv']);
    }

    public function test_rejects_empty_input(): void
    {
        $this->assertParseError('   ', 'empty');
    }

    public function test_rejects_excessive_input(): void
    {
        $this->assertParseError(str_repeat('a', 501), 'too_long');
    }

    public function test_rejects_unknown_commands(): void
    {
        $this->assertParseError('migrate:fresh --force', 'unknown_command');
        $this->assertParseError('db:wipe', 'unknown_command');
        $this->assertParseError('tinker', 'unknown_command');
        $this->assertParseError('key:generate', 'unknown_command');
        $this->assertParseError('make:controller Evil', 'unknown_command');
    }

    public function test_rejects_unknown_options(): void
    {
        $this->assertParseError('db:seed --class=DatabaseSeeder --anything', 'unknown_option');
        $this->assertParseError('cache:clear --store=redis', 'unknown_option');
        $this->assertParseError('about -v', 'unexpected_argument');
    }

    public function test_rejects_unapproved_seeder_classes(): void
    {
        $this->assertParseError('db:seed --class=App\\Seeders\\EvilSeeder', 'bad_value');
        $this->assertParseError('db:seed --class=FooBarSeeder', 'bad_value');
        $this->assertParseError('db:seed --class=../../Evil', 'bad_value');
    }

    public function test_rejects_value_on_flag_options(): void
    {
        $this->assertParseError('db:seed --force=yes', 'bad_value');
    }

    public function test_rejects_unexpected_arguments(): void
    {
        $this->assertParseError('db:seed DatabaseSeeder', 'unexpected_argument');
    }

    #[DataProvider('injectionProvider')]
    public function test_rejects_shell_and_control_characters(string $input): void
    {
        $this->assertParseError($input, 'injection');
    }

    public static function injectionProvider(): array
    {
        return [
            'command chaining semicolon' => ['db:seed; rm -rf /'],
            'pipe' => ['db:seed | cat /etc/passwd'],
            'pipe double' => ['db:seed || whoami'],
            'logical and' => ['db:seed && whoami'],
            'output redirection' => ['db:seed > /tmp/x'],
            'input redirection' => ['db:seed < /tmp/x'],
            'backticks' => ['db:seed `whoami`'],
            'command substitution' => ['db:seed --class=$(whoami)Seeder'],
            'newline injection' => ["db:seed\nmigrate:fresh"],
            'carriage return injection' => ["db:seed\rmigrate:fresh"],
            'null byte' => ["db:seed\x00--class=DatabaseSeeder"],
            'tab injection' => ["db:seed\t--class=DatabaseSeeder"],
            'vertical tab' => ["db:seed\v--class=DatabaseSeeder"],
        ];
    }

    public function test_rejects_unbalanced_quotes(): void
    {
        $this->assertParseError('db:seed --class="DatabaseSeeder', 'injection');
    }

    public function test_accepts_all_registered_commands_without_options(): void
    {
        foreach (ArtisanRunner::catalog() as $entry) {
            if ($entry['name'] === 'db:seed') {
                continue; // requires its options to be valid
            }

            $parsed = $this->runner->parse($entry['name']);

            $this->assertSame([$entry['name']], $parsed['argv']);
        }
    }

    public function test_catalog_lists_every_allowed_command(): void
    {
        $catalog = ArtisanRunner::catalog();
        $names = array_column($catalog, 'name');

        $this->assertContains('db:seed', $names);
        $this->assertContains('optimize:clear', $names);
        $this->assertContains('cache:clear', $names);
        $this->assertContains('config:clear', $names);
        $this->assertContains('route:clear', $names);
        $this->assertContains('view:clear', $names);
        $this->assertContains('about', $names);
        $this->assertContains('route:list', $names);
        $this->assertContains('storage:link', $names);
        $this->assertCount(9, $catalog);
    }

    public function test_output_truncation_caps_output_at_the_limit(): void
    {
        $truncate = new ReflectionMethod(ArtisanRunner::class, 'truncate');

        $small = $truncate->invoke($this->runner, 'short output');
        $this->assertSame('short output', $small);

        $huge = $truncate->invoke($this->runner, str_repeat('x', ArtisanRunner::MAX_OUTPUT_BYTES + 5000));
        $this->assertLessThanOrEqual(ArtisanRunner::MAX_OUTPUT_BYTES + 100, strlen($huge));
        $this->assertStringContainsString('[output truncated', $huge);
    }

    public function test_dangerous_commands_are_never_registered(): void
    {
        $registered = implode(' ', array_keys(ArtisanRunner::COMMANDS));

        foreach (['tinker', 'db:wipe', 'migrate:fresh', 'key:generate', 'migrate:rollback'] as $dangerous) {
            $this->assertStringNotContainsString($dangerous, $registered);
        }
    }

    protected function assertParseError(string $input, string $reason): void
    {
        try {
            $this->runner->parse($input);
            $this->fail("Expected ArtisanCommandException ({$reason}) for input: {$input}");
        } catch (ArtisanCommandException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
