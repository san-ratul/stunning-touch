<?php

namespace Tests\Unit;

use App\Models\AiSetting;
use App\Services\Ai\AiProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function seedSettings(array $overrides = []): AiSetting
    {
        return AiSetting::create($overrides + [
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'model' => 'test-model',
            'is_active' => true,
            'retry_backoff_ms' => 0,
            'max_retries' => 2,
        ]);
    }

    public function test_bearer_mode_sends_authorization_header(): void
    {
        $this->seedSettings(['auth_mode' => 'bearer']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);

        app(AiProviderService::class)->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer sk-test');
    }

    public function test_auth_none_omits_authorization_header(): void
    {
        $this->seedSettings(['auth_mode' => 'none', 'api_key' => null]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);

        $service = app(AiProviderService::class);
        $this->assertTrue($service->isConfigured(), 'Ollama endpoints must work without an API key.');

        $service->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(fn ($request) => $request->header('Authorization') === []);
    }

    public function test_basic_mode_sends_basic_auth(): void
    {
        $this->seedSettings([
            'auth_mode' => 'basic',
            'basic_auth_user' => 'proxyuser',
            'basic_auth_password' => 'proxypass',
        ]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);

        app(AiProviderService::class)->chat([['role' => 'user', 'content' => 'Hi']]);

        Http::assertSent(function ($request) {
            return str_starts_with((string) $request->header('Authorization')[0], 'Basic ');
        });
    }

    public function test_retries_on_500_then_succeeds(): void
    {
        $this->seedSettings();
        Http::fake(['*' => Http::sequence()
            ->push('server error', 500)
            ->push(['choices' => [['message' => ['content' => 'Recovered']]]])]);

        $reply = app(AiProviderService::class)->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertEquals('Recovered', $reply);
    }

    public function test_retries_on_429_then_succeeds(): void
    {
        $this->seedSettings();
        Http::fake(['*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429)
            ->push(['choices' => [['message' => ['content' => 'After limit']]]])]);

        $reply = app(AiProviderService::class)->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertEquals('After limit', $reply);
    }

    public function test_does_not_retry_on_401(): void
    {
        $this->seedSettings();
        Http::fake(['*' => Http::sequence()
            ->push('denied', 401)
            ->push(['choices' => [['message' => ['content' => 'Should not happen']]]])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('401');

        try {
            app(AiProviderService::class)->chat([['role' => 'user', 'content' => 'Hi']]);
        } finally {
            Http::assertSent(fn ($request) => true);
            $this->assertEquals(1, count(Http::recorded()));
        }
    }

    public function test_retries_connection_failures_then_throws(): void
    {
        $this->seedSettings(['max_retries' => 2]);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            throw new ConnectionException('cURL error 28: connection timed out');
        });

        try {
            app(AiProviderService::class)->chat([['role' => 'user', 'content' => 'Hi']]);
            $this->fail('Expected RuntimeException after exhausting retries.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('after 3 attempt(s)', $e->getMessage());
        }

        $this->assertEquals(3, $calls, 'Connection failures must be retried max_retries times.');
    }

    public function test_ollama_native_payload_and_usage_normalization(): void
    {
        AiSetting::create([
            'provider' => 'ollama',
            'endpoint' => 'http://ai.example.test:11434/api/chat',
            'auth_mode' => 'none',
            'model' => 'qwen3.5:4b',
            'context_length' => 16384,
            'keep_alive' => '10m',
            'is_active' => true,
            'retry_backoff_ms' => 0,
        ]);

        Http::fake([
            'http://ai.example.test:11434/api/chat' => Http::response([
                'model' => 'qwen3.5:4b',
                'message' => ['role' => 'assistant', 'content' => 'Native reply'],
                'prompt_eval_count' => 100,
                'eval_count' => 50,
            ]),
        ]);

        $result = app(AiProviderService::class)->chatWithUsage([['role' => 'user', 'content' => 'Hi']], maxTokens: 6000);

        $this->assertEquals('Native reply', $result['content']);
        $this->assertEquals(['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150], $result['usage']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return ($payload['options']['num_ctx'] ?? null) === 16384
                && ($payload['options']['num_predict'] ?? null) === 6000
                && ($payload['keep_alive'] ?? null) === '10m'
                && $payload['stream'] === false;
        });
    }

    public function test_usage_normalization_accepts_both_formats(): void
    {
        $service = app(AiProviderService::class);

        $openai = $service->normalizeUsage(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]);
        $this->assertEquals(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15], $openai);

        $ollama = $service->normalizeUsage(['prompt_eval_count' => 8, 'eval_count' => 4]);
        $this->assertEquals(['prompt_tokens' => 8, 'completion_tokens' => 4, 'total_tokens' => 12], $ollama);

        $this->assertNull($service->normalizeUsage(null));
        $this->assertNull($service->normalizeUsage(['foo' => 'bar']));
    }

    public function test_generation_lock_prevents_concurrent_execution(): void
    {
        $service = app(AiProviderService::class);

        $result = $service->withGenerationLock(fn () => 'ran', ttlSeconds: 60);
        $this->assertEquals('ran', $result);

        // Hold the lock manually, then verify the service refuses to run.
        $lock = Cache::lock('ai:generation', 60);
        $this->assertTrue($lock->acquire());

        $this->assertNull($service->withGenerationLock(fn () => 'ran', ttlSeconds: 60));

        $lock->release();
    }

    public function test_model_availability_openai_compatible(): void
    {
        $this->seedSettings(['model' => 'gpt-test']);
        Http::fake([
            'https://api.example.com/v1/models' => Http::response(['data' => [['id' => 'gpt-test'], ['id' => 'other']]]),
            'https://api.example.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'OK']]]]),
        ]);

        $result = app(AiProviderService::class)->modelAvailability();

        $this->assertTrue($result['success'], $result['message']);
        $this->assertContains('gpt-test', $result['models']);
    }

    public function test_model_availability_ollama_tags(): void
    {
        AiSetting::create([
            'provider' => 'ollama',
            'endpoint' => 'http://ai.example.test:11434/api/chat',
            'auth_mode' => 'none',
            'model' => 'qwen3.5:4b',
            'is_active' => true,
        ]);
        Http::fake([
            'http://ai.example.test:11434/api/tags' => Http::response([
                'models' => [['name' => 'qwen3.5:4b'], ['name' => 'llama3.1:8b']],
            ]),
        ]);

        $result = app(AiProviderService::class)->modelAvailability();

        $this->assertTrue($result['success'], $result['message']);
    }

    public function test_model_availability_reports_missing_model(): void
    {
        $this->seedSettings(['model' => 'missing-model']);
        Http::fake([
            'https://api.example.com/v1/models' => Http::response(['data' => [['id' => 'gpt-test']]]),
        ]);

        $result = app(AiProviderService::class)->modelAvailability();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('missing-model', $result['message']);
    }
}
