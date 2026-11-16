<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests for the dynamic model-discovery endpoint
 * (POST admin/ai-settings/models).
 *
 * All provider HTTP traffic is mocked; no real API quota is consumed.
 */
class AiModelDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        $this->seed(DatabaseSeeder::class);

        return User::first();
    }

    protected function fetch(array $payload = [])
    {
        return $this->actingAs($this->admin())
            ->postJson(route('admin.ai-settings.models'), $payload);
    }

    // ---- Successful discovery per provider type ----

    public function test_openai_compatible_discovery_lists_models(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response([
                'data' => [['id' => 'gpt-4o'], ['id' => 'gpt-3.5-turbo']],
            ]),
        ]);

        $this->fetch(['endpoint' => 'https://api.openai.com/v1', 'api_key' => 'sk-openai-test-key'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('models.0', 'gpt-3.5-turbo')
            ->assertJsonPath('models.1', 'gpt-4o')
            ->assertJsonPath('message', 'Retrieved 2 models.');
    }

    public function test_groq_discovery_lists_models(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/models' => Http::response([
                'data' => [
                    ['id' => 'llama-3.1-8b-instant'],
                    ['id' => 'openai/gpt-oss-120b'],
                ],
            ]),
        ]);

        $this->fetch(['endpoint' => 'https://api.groq.com/openai/v1', 'api_key' => 'gsk-groq-test-key'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $models = $this->fetch(['endpoint' => 'https://api.groq.com/openai/v1', 'api_key' => 'gsk-groq-test-key'])->json('models');
        $this->assertContains('openai/gpt-oss-120b', $models);
    }

    public function test_openrouter_discovery_lists_models(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [['id' => 'openai/gpt-4o'], ['id' => 'anthropic/claude-3.5-sonnet']],
            ]),
        ]);

        $this->fetch(['endpoint' => 'https://openrouter.ai/api/v1', 'api_key' => 'sk-or-test-key'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['models' => ['anthropic/claude-3.5-sonnet', 'openai/gpt-4o']]);
    }

    public function test_ollama_native_discovery_uses_api_tags(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/api/tags' => Http::response([
                'models' => [['name' => 'qwen3.5:4b', 'model' => 'qwen3.5:4b'], ['name' => 'llama3.2:3b']],
            ]),
        ]);

        $this->fetch(['endpoint' => 'http://127.0.0.1:11434/api/chat', 'auth_mode' => 'none'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['models' => ['llama3.2:3b', 'qwen3.5:4b']]);

        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:11434/api/tags');
    }

    // ---- Credentials ----

    public function test_stored_api_key_is_used_when_none_is_submitted(): void
    {
        AiSetting::create([
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.openai.com/v1',
            'api_key' => 'sk-stored-key-1234567890',
            'auth_mode' => 'bearer',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-4o']]]),
        ]);

        $this->fetch(['endpoint' => 'https://api.openai.com/v1'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            return $request->header('Authorization')[0] === 'Bearer sk-stored-key-1234567890';
        });
    }

    public function test_blank_key_without_storage_asks_for_a_key(): void
    {
        $this->fetch(['endpoint' => 'https://api.openai.com/v1', 'auth_mode' => 'bearer'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('auth_required', true)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'API key is required'));

        Http::assertNothingSent();
    }

    public function test_invalid_api_key_is_reported_without_echoing_it(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['error' => ['message' => 'Invalid API key']], 401),
        ]);

        $this->fetch(['endpoint' => 'https://api.openai.com/v1', 'api_key' => 'sk-wrong-key-secret'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Invalid or unauthorized API key'))
            ->assertJsonPath('message', fn ($m) => ! str_contains($m, 'sk-wrong-key-secret'));
    }

    // ---- Failure modes ----

    public function test_unsupported_model_list_endpoint_suggests_manual_entry(): void
    {
        Http::fake([
            'https://some-provider.example.com/v1/models' => Http::response('', 404),
        ]);

        $this->fetch(['endpoint' => 'https://some-provider.example.com/v1', 'api_key' => 'k'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Automatic model discovery is not supported by this provider. You can enter a model ID manually.');
    }

    public function test_provider_timeout_is_reported(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->fetch(['endpoint' => 'https://api.openai.com/v1', 'api_key' => 'k'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Could not reach the provider'));
    }

    public function test_malformed_json_is_reported(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response('<html>gateway error</html>', 200),
        ]);

        $this->fetch(['endpoint' => 'https://api.openai.com/v1', 'api_key' => 'k'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'malformed model list'));
    }

    public function test_empty_model_list_is_reported_with_manual_fallback(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['data' => []]),
        ]);

        $this->fetch(['endpoint' => 'https://api.openai.com/v1', 'api_key' => 'k'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'empty model list') && str_contains($m, 'enter a model ID manually'));
    }

    public function test_rate_limit_is_reported(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['error' => ['message' => 'slow down']], 429),
        ]);

        $this->fetch(['endpoint' => 'https://api.openai.com/v1', 'api_key' => 'k'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'rate limited'));
    }

    public function test_duplicate_model_ids_are_removed(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response([
                'data' => [['id' => 'gpt-4o'], ['id' => 'gpt-4o'], ['id' => 'gpt-4o-mini'], ['name' => 'gpt-4o']],
            ]),
        ]);

        $models = $this->fetch(['endpoint' => 'https://api.openai.com/v1', 'api_key' => 'k'])->json('models');

        $this->assertSame(['gpt-4o', 'gpt-4o-mini'], $models);
    }

    public function test_data_name_fallback_is_used_when_id_is_missing(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response([
                'data' => [['name' => 'weird-provider-model']],
            ]),
        ]);

        $this->fetch(['endpoint' => 'https://api.openai.com/v1', 'api_key' => 'k'])
            ->assertOk()
            ->assertJsonPath('models', ['weird-provider-model']);
    }

    public function test_model_ids_are_preferred_over_display_names(): void
    {
        // Regression (verified live against Groq): the API needs the id —
        // "openai/gpt-oss-120b" — not the display name "GPT OSS 120B".
        Http::fake([
            'https://api.groq.com/openai/v1/models' => Http::response([
                'data' => [['id' => 'openai/gpt-oss-120b', 'name' => 'GPT OSS 120B']],
            ]),
        ]);

        $this->fetch(['endpoint' => 'https://api.groq.com/openai/v1', 'api_key' => 'k'])
            ->assertOk()
            ->assertJsonPath('models', ['openai/gpt-oss-120b']);
    }

    // ---- Security ----

    public function test_ssrf_metadata_endpoints_are_blocked(): void
    {
        $this->fetch(['endpoint' => 'http://169.254.169.254/latest/meta-data', 'api_key' => 'k'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'link-local'));

        $this->fetch(['endpoint' => 'https://metadata.google.internal/computeMetadata/v1', 'api_key' => 'k'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'metadata'));

        Http::assertNothingSent();
    }

    public function test_non_http_scheme_is_blocked(): void
    {
        $this->fetch(['endpoint' => 'ftp://files.example.com/models', 'api_key' => 'k'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'http or https'));

        Http::assertNothingSent();
    }

    public function test_api_key_never_appears_in_responses_or_logs(): void
    {
        $secret = 'sk-never-leak-this-key-42';
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-4o']]]),
        ]);

        $response = $this->fetch(['endpoint' => 'https://api.openai.com/v1', 'api_key' => $secret])
            ->assertOk();

        $this->assertStringNotContainsString($secret, $response->getContent());

        $log = @file_get_contents(storage_path('logs/laravel.log')) ?: '';
        $this->assertStringNotContainsString($secret, $log);
    }

    public function test_guests_cannot_discover_models(): void
    {
        $this->postJson(route('admin.ai-settings.models'), ['endpoint' => 'https://api.openai.com/v1'])
            ->assertUnauthorized();

        Http::assertNothingSent();
    }

    // ---- Manual entry and save flow ----

    public function test_manually_entered_model_still_saves_and_loads(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.ai-settings.update'), [
                'provider' => 'openai-compatible',
                'endpoint' => 'https://some-provider.example.com/v1',
                'api_key' => 'sk-manual-entry-key',
                'auth_mode' => 'bearer',
                'model' => 'my-manual-model-id',
                'is_active' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $settings = AiSetting::current();
        $this->assertSame('my-manual-model-id', $settings->model);

        // The saved model must appear preselected after a page reload.
        $this->actingAs($this->admin())
            ->get(route('admin.ai-settings.edit'))
            ->assertOk()
            ->assertSee('my-manual-model-id', false);
    }

    public function test_saved_model_survives_failed_discovery_and_page_reload(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.ai-settings.update'), [
                'provider' => 'openai-compatible',
                'endpoint' => 'https://api.groq.com/openai/v1',
                'api_key' => 'gsk-groq-test-key',
                'auth_mode' => 'bearer',
                'model' => 'openai/gpt-oss-120b',
                'is_active' => '1',
            ])
            ->assertRedirect();

        // Discovery fails afterwards — the saved model must not be touched.
        Http::fake([
            'https://api.groq.com/openai/v1/models' => Http::response('', 404),
        ]);

        $this->fetch(['endpoint' => 'https://api.groq.com/openai/v1'])
            ->assertOk()
            ->assertJsonPath('success', false);

        $this->assertSame('openai/gpt-oss-120b', AiSetting::current()->refresh()->model);

        // @js() escapes slashes in the initial-model config; assert the
        // escaped form so the reloaded page really shows the saved model.
        $this->actingAs($this->admin())
            ->get(route('admin.ai-settings.edit'))
            ->assertOk()
            ->assertSee('openai\/gpt-oss-120b', false);
    }
}
