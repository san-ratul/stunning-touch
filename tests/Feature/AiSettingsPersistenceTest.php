<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\Ai\AiProviderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSettingsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function admin()
    {
        $this->seed(DatabaseSeeder::class);

        return User::first();
    }

    protected function geminiPayload(array $overrides = []): array
    {
        return $overrides + [
            'provider' => 'google-gemini',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'model' => 'gemini-3.8-flash',
            'auth_mode' => 'bearer',
            'api_key' => 'AIzaSyTestKeyForPersistenceChecks123',
            'is_active' => '1',
            'timeout_seconds' => 900,
            'max_retries' => 2,
        ];
    }

    public function test_full_configuration_persists_and_encrypts_key(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);

        $this->actingAs($this->admin())
            ->put(route('admin.ai-settings.update'), $this->geminiPayload())
            ->assertRedirect();

        $row = AiSetting::query()->firstOrFail();

        $this->assertEquals('google-gemini', $row->provider);
        $this->assertEquals('https://generativelanguage.googleapis.com/v1beta/openai/', $row->endpoint);
        $this->assertEquals('gemini-3.8-flash', $row->model);
        $this->assertEquals('bearer', $row->auth_mode);
        $this->assertTrue($row->is_active);

        // Stored encrypted: raw value differs from the plaintext and decrypts correctly.
        $raw = $row->getRawOriginal('api_key');
        $this->assertNotEquals('AIzaSyTestKeyForPersistenceChecks123', $raw);
        $this->assertEquals('AIzaSyTestKeyForPersistenceChecks123', $row->api_key);

        $service = app(AiProviderService::class);
        $this->assertTrue($service->isConfigured());

        $state = $service->configurationState();
        $this->assertTrue($state['configured']);
        $this->assertTrue($state['enabled']);
        $this->assertTrue($state['has_key']);
    }

    public function test_blank_key_field_preserves_stored_key_and_state_survives_refresh(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.ai-settings.update'), $this->geminiPayload());

        // Second save with a blank key field (the form never re-renders keys).
        $this->actingAs($this->admin())
            ->put(route('admin.ai-settings.update'), $this->geminiPayload([
                'api_key' => '',
                'provider' => 'google-gemini-renamed',
            ]))
            ->assertRedirect();

        $row = AiSetting::query()->firstOrFail();

        $this->assertEquals('AIzaSyTestKeyForPersistenceChecks123', $row->api_key, 'Blank key field must never overwrite the stored key.');
        $this->assertEquals('google-gemini-renamed', $row->provider);
        $this->assertTrue($row->is_active);

        // Reload page: configuration ready banner must render.
        $this->actingAs($this->admin())
            ->get(route('admin.ai-settings.edit'))
            ->assertOk()
            ->assertSee('Configuration ready');
    }

    public function test_new_key_replaces_stored_key(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.ai-settings.update'), $this->geminiPayload());

        $this->actingAs($this->admin())
            ->put(route('admin.ai-settings.update'), $this->geminiPayload(['api_key' => 'AIzaSyNewReplacementKey987']))
            ->assertRedirect();

        $this->assertEquals('AIzaSyNewReplacementKey987', AiSetting::query()->firstOrFail()->api_key);
    }

    public function test_bearer_provider_cannot_be_enabled_without_any_key(): void
    {
        // Seed an empty row (the state the production DB was found in).
        AiSetting::create([
            'provider' => 'google-gemini',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'model' => 'gemini-3.8-flash',
            'auth_mode' => 'bearer',
            'is_active' => false,
        ]);

        $this->actingAs($this->admin())
            ->from(route('admin.ai-settings.edit'))
            ->put(route('admin.ai-settings.update'), $this->geminiPayload(['api_key' => '']))
            ->assertRedirect(route('admin.ai-settings.edit'))
            ->assertSessionHasErrors('api_key');

        $row = AiSetting::query()->firstOrFail();
        $this->assertFalse($row->is_active, 'A bearer provider without a key must not be enabled.');
        $this->assertFalse(app(AiProviderService::class)->isConfigured());

        // The row is complete but disabled, so the admin gets the explicit
        // disabled message rather than a generic "not fully configured".
        $message = app(AiProviderService::class)->testConnection()['message'];
        $this->assertStringContainsString('disabled', $message);
    }

    public function test_disabled_provider_gets_an_explicit_message(): void
    {
        AiSetting::create($this->geminiPayload(['api_key' => 'AIzaSyCompleteKey456', 'is_active' => false]));

        $service = app(AiProviderService::class);
        $state = $service->configurationState();

        $this->assertTrue($state['configured'], 'Complete settings must report configured.');
        $this->assertFalse($state['enabled']);

        $message = $service->testConnection()['message'];
        $this->assertStringContainsString('disabled', $message);
        $this->assertStringContainsString('Enable AI content generation', $message);
    }

    public function test_enabled_provider_without_key_reports_missing_key(): void
    {
        AiSetting::create([
            'provider' => 'google-gemini',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'model' => 'gemini-3.8-flash',
            'auth_mode' => 'bearer',
            'is_active' => true,
        ]);

        $service = app(AiProviderService::class);

        $this->assertFalse($service->isConfigured());
        $this->assertEquals(['API key'], $service->configurationState()['missing']);

        $message = $service->testConnection()['message'];
        $this->assertStringContainsString('incomplete', strtolower($message));
        $this->assertStringContainsString('API key', $message);
    }

    public function test_keyless_none_mode_remains_configured(): void
    {
        AiSetting::create([
            'provider' => 'ollama',
            'endpoint' => 'http://127.0.0.1:11434/api/chat',
            'auth_mode' => 'none',
            'model' => 'qwen3.5:4b',
            'is_active' => true,
        ]);

        $service = app(AiProviderService::class);

        $this->assertTrue($service->isConfigured(), 'Keyless Ollama must stay configured.');
        $this->assertSame([], $service->configurationState()['missing']);
    }

    public function test_incomplete_configuration_is_visible_on_the_admin_page(): void
    {
        $admin = $this->admin();

        AiSetting::create([
            'provider' => 'google-gemini',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'model' => 'gemini-3.8-flash',
            'auth_mode' => 'bearer',
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.ai-settings.edit'))
            ->assertOk()
            ->assertSee('Incomplete configuration')
            ->assertSee('API key')
            ->assertSee('currently disabled');
    }
}
