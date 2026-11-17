<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ArtisanRunner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Frontend contract regression tests for the Artisan Command Runner.
 *
 * Root-cause regression: the inline runner script was stacked in the
 * layout <head>, executing before the page body existed, so the
 * [data-artisan-runner] root was null and the Run Command button never
 * got any event listeners ("button does nothing"). These tests lock the
 * fixed contract: the script must render after the DOM root and before
 * </body>, with the correct selectors, endpoint and status handling.
 */
class ArtisanRunnerFrontendTest extends TestCase
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

    protected function runnerScript(string $html): string
    {
        preg_match_all('/<script>(.*?)<\/script>/s', $html, $matches);

        foreach ($matches[1] as $script) {
            if (str_contains($script, 'initArtisanRunner')) {
                return $script;
            }
        }

        return '';
    }

    public function test_runner_page_contains_all_interactive_elements(): void
    {
        $response = $this->actingAs($this->authorizedAdmin)->get('/admin/artisan-runner');

        $response->assertOk();

        foreach ([
            'data-artisan-runner',
            'data-command-input',
            'data-run-button',
            'data-clear-button',
            'data-loading',
            'data-error',
            'data-result',
            'data-result-stdout',
            'data-result-stderr',
        ] as $selector) {
            $response->assertSee($selector, false);
        }
    }

    public function test_runner_script_renders_after_the_dom_root(): void
    {
        $html = $this->actingAs($this->authorizedAdmin)->get('/admin/artisan-runner')->getContent();

        $script = $this->runnerScript($html);

        $this->assertNotSame('', $script, 'The runner script must be rendered on the page.');

        $rootPos = strpos($html, 'data-artisan-runner');
        $scriptPos = strpos($html, 'initArtisanRunner');

        $this->assertNotFalse($rootPos);
        $this->assertNotFalse($scriptPos);
        $this->assertLessThan($scriptPos, $rootPos, 'The runner script must execute after the [data-artisan-runner] root exists in the DOM.');
    }

    public function test_runner_script_renders_before_body_close_not_in_head(): void
    {
        $html = $this->actingAs($this->authorizedAdmin)->get('/admin/artisan-runner')->getContent();

        $scriptPos = strpos($html, 'initArtisanRunner');
        $headEnd = (int) strpos($html, '</head>');
        $bodyEnd = (int) strpos($html, '</body>');

        $this->assertNotSame(0, $headEnd);
        $this->assertGreaterThan($headEnd, $scriptPos, 'The runner script must NOT be stacked inside <head> — it would run before the DOM exists.');
        $this->assertLessThan($bodyEnd, $scriptPos, 'The runner script must render inside the document body.');
    }

    public function test_runner_script_targets_the_correct_endpoint_and_headers(): void
    {
        $script = $this->runnerScript(
            $this->actingAs($this->authorizedAdmin)->get('/admin/artisan-runner')->getContent(),
        );

        $this->assertStringContainsString('artisan-runner/run', $script, 'The script must POST to the correct endpoint.');
        $this->assertStringContainsString('X-CSRF-TOKEN', $script, 'The script must send the CSRF token.');
        $this->assertStringContainsString("'POST'", $script, 'Execution must be a POST request.');
        $this->assertStringContainsString("method: 'POST'", $script);
    }

    public function test_runner_script_registers_run_and_clear_buttons_and_busy_guard(): void
    {
        $script = $this->runnerScript(
            $this->actingAs($this->authorizedAdmin)->get('/admin/artisan-runner')->getContent(),
        );

        $this->assertStringContainsString('[data-run-button]', $script);
        $this->assertStringContainsString("addEventListener('click', run)", $script);
        $this->assertStringContainsString('[data-clear-button]', $script);
        $this->assertStringContainsString('busy', $script, 'The script must guard against duplicate submissions.');
        $this->assertStringContainsString('disabled = true', $script, 'The run button must be disabled while running.');
    }

    public function test_runner_script_handles_all_response_classes(): void
    {
        $script = $this->runnerScript(
            $this->actingAs($this->authorizedAdmin)->get('/admin/artisan-runner')->getContent(),
        );

        foreach ([401, 403, 419, 429] as $status) {
            $this->assertStringContainsString('response.status === '.$status, $script, "HTTP {$status} must be handled with a visible message.");
        }

        $this->assertStringContainsString('catch', $script, 'Network failures must be handled.');
        $this->assertStringContainsString('Unexpected response', $script, 'Invalid JSON responses must be surfaced.');
    }

    public function test_runner_script_initializes_after_dom_is_ready(): void
    {
        $script = $this->runnerScript(
            $this->actingAs($this->authorizedAdmin)->get('/admin/artisan-runner')->getContent(),
        );

        $this->assertStringContainsString('readyState', $script);
        $this->assertStringContainsString('DOMContentLoaded', $script, 'The script must defer initialization until the DOM is ready.');
        $this->assertStringContainsString('initArtisanRunner()', $script);
    }

    public function test_runner_page_and_endpoint_are_hidden_from_guests(): void
    {
        $response = $this->get('/admin/artisan-runner');

        $this->assertTrue(in_array($response->getStatusCode(), [301, 302], true));

        // The public response must not expose the execution endpoint.
        $this->assertStringNotContainsString('artisan-runner/run', $response->getContent());
    }

    public function test_run_endpoint_sets_no_store_cache_headers(): void
    {
        $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'about'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_allowed_commands_reference_is_rendered(): void
    {
        $response = $this->actingAs($this->authorizedAdmin)->get('/admin/artisan-runner');

        foreach (ArtisanRunner::catalog() as $item) {
            $response->assertSee($item['name'], false);
        }
    }
}
