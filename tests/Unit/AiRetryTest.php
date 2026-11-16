<?php

namespace Tests\Unit;

use App\Models\AiSetting;
use App\Services\Ai\AiProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Retry handling and output-budget behavior for the Groq GPT-OSS failure
 * class observed live:
 *
 * 1. HTTP 503 "high demand" from Groq after retries.
 * 2. HTTP 200 with finish_reason=length and empty assistant content — the
 *    reasoning model consumed its entire output budget invisibly.
 */
class AiRetryTest extends TestCase
{
    use RefreshDatabase;

    protected const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    protected function seedProvider(array $overrides = []): AiSetting
    {
        return AiSetting::create($overrides + [
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.groq.com/openai/v1',
            'api_key' => 'gsk-test-key-000',
            'auth_mode' => 'bearer',
            'model' => 'openai/gpt-oss-120b',
            'is_active' => true,
            'max_retries' => 1,
            // Zero base keeps tests fast; jitter multiplies zero to zero.
            'retry_backoff_ms' => 0,
        ]);
    }

    protected function contentResponse(string $content, ?array $usage = null): array
    {
        return [
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'usage' => $usage ?? ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ];
    }

    protected function exhaustedResponse(?string $reasoning = null, ?array $usage = null): array
    {
        $message = ['content' => ''];
        if ($reasoning !== null) {
            $message['reasoning'] = $reasoning;
        }

        return [
            'choices' => [['message' => $message, 'finish_reason' => 'length']],
            'usage' => $usage ?? ['prompt_tokens' => 100, 'completion_tokens' => 6000],
        ];
    }

    // ---- Request token parameters (investigation result) ----

    public function test_gpt_oss_request_sends_max_tokens_parameter(): void
    {
        $this->seedProvider();
        Http::fake([
            self::ENDPOINT => Http::response($this->contentResponse('OK')),
        ]);

        app(AiProviderService::class)->chatWithUsage(
            [['role' => 'user', 'content' => 'Hi']],
            maxTokens: 6000
        );

        Http::assertSent(fn ($request) => $request['model'] === 'openai/gpt-oss-120b'
            && $request['max_tokens'] === 6000
            && ! isset($request['max_completion_tokens']));
    }

    // ---- finish_reason=length handling ----

    public function test_length_exhausted_budget_triggers_one_larger_budget_retry(): void
    {
        $this->seedProvider();

        Http::fake([
            self::ENDPOINT => Http::sequence()
                ->push($this->exhaustedResponse('long invisible reasoning…', ['prompt_tokens' => 100, 'completion_tokens' => 6000]))
                ->push($this->contentResponse('Final article content', ['prompt_tokens' => 100, 'completion_tokens' => 12000])),
        ]);

        $result = app(AiProviderService::class)->chatWithUsage(
            [['role' => 'user', 'content' => 'Write an article']],
            maxTokens: 6000
        );

        $this->assertSame('Final article content', $result['content']);

        // Token accounting must aggregate BOTH attempts.
        $this->assertEquals(200, $result['usage']['prompt_tokens']);
        $this->assertEquals(18000, $result['usage']['completion_tokens']);

        $sent = Http::recorded();
        $this->assertCount(2, $sent, 'Exactly one controlled budget retry — never a loop.');
        $this->assertEquals(6000, $sent[0][0]['max_tokens']);
        $this->assertEquals(12000, $sent[1][0]['max_tokens'], 'The retry must use a doubled budget, not an identical request.');
    }

    public function test_length_budget_retry_that_also_fails_gives_precise_diagnostic(): void
    {
        $this->seedProvider();

        Http::fake([
            self::ENDPOINT => Http::sequence()
                ->push($this->exhaustedResponse())
                ->push($this->exhaustedResponse()),
        ]);

        try {
            app(AiProviderService::class)->chatWithUsage(
                [['role' => 'user', 'content' => 'Write an article']],
                maxTokens: 6000
            );
            $this->fail('An exhausted output budget must fail loudly.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('finish_reason=length', $e->getMessage());
            $this->assertStringContainsString('output budget', $e->getMessage());
            $this->assertStringContainsString('Max output tokens', $e->getMessage());
            $this->assertStringContainsString('non-reasoning model', $e->getMessage());
        }

        $this->assertCount(2, Http::recorded(), 'Only one budget retry, then a precise failure.');
    }

    public function test_reasoning_without_visible_content_is_never_treated_as_content(): void
    {
        $this->seedProvider();

        // maxTokens at the ceiling: no budget retry possible — fail fast.
        Http::fake([
            self::ENDPOINT => Http::response($this->exhaustedResponse('reasoning only, no article')),
        ]);

        try {
            app(AiProviderService::class)->chatWithUsage(
                [['role' => 'user', 'content' => 'Write an article']],
                maxTokens: 16000
            );
            $this->fail('Reasoning text must never be treated as the final article.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reasoning=present', $e->getMessage());
            $this->assertStringContainsString('finish_reason=length', $e->getMessage());
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_successful_response_with_visible_content_passes_through(): void
    {
        $this->seedProvider();
        Http::fake([
            self::ENDPOINT => Http::response($this->contentResponse('Hello article')),
        ]);

        $result = app(AiProviderService::class)->chatWithUsage(
            [['role' => 'user', 'content' => 'Hi']],
            maxTokens: 6000
        );

        $this->assertSame('Hello article', $result['content']);
        $this->assertCount(1, Http::recorded(), 'A healthy response must not be re-requested.');
    }

    // ---- HTTP 503 handling ----

    public function test_503_is_retried_and_then_succeeds(): void
    {
        $this->seedProvider();

        Http::fake([
            self::ENDPOINT => Http::sequence()
                ->push(['error' => ['code' => 503, 'message' => 'high demand']], 503)
                ->push($this->contentResponse('Recovered')),
        ]);

        $result = app(AiProviderService::class)->chatWithUsage(
            [['role' => 'user', 'content' => 'Hi']],
            maxTokens: 100
        );

        $this->assertSame('Recovered', $result['content']);
        $this->assertCount(2, Http::recorded());
    }

    public function test_503_after_exhausted_retries_preserves_status_and_detail(): void
    {
        $this->seedProvider(['max_retries' => 2]);

        Http::fake([
            self::ENDPOINT => Http::response([
                'error' => ['code' => 503, 'message' => 'This model is currently experiencing high demand.'],
            ], 503),
        ]);

        try {
            app(AiProviderService::class)->chatWithUsage(
                [['role' => 'user', 'content' => 'Hi']],
                maxTokens: 100
            );
            $this->fail('A persistent 503 must fail loudly.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('AI API error 503 after 3 attempt(s)', $e->getMessage());
            $this->assertStringContainsString('high demand', $e->getMessage());
        }

        $this->assertCount(3, Http::recorded());
    }

    public function test_retry_after_header_is_respected(): void
    {
        $this->seedProvider();

        $service = Mockery::mock(AiProviderService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('sleepBackoff')
            ->once()
            ->with(0, 1, 7)
            ->andReturnNull();

        Http::fake([
            self::ENDPOINT => Http::sequence()
                ->push(['error' => ['code' => 503, 'message' => 'busy']], 503, ['Retry-After' => '7'])
                ->push($this->contentResponse('OK after waiting')),
        ]);

        $result = $service->chatWithUsage([['role' => 'user', 'content' => 'Hi']], maxTokens: 100);

        $this->assertSame('OK after waiting', $result['content']);
        Mockery::close();
    }

    public function test_backoff_uses_jitter_within_bounded_range(): void
    {
        $service = new AiProviderService;
        $method = new \ReflectionMethod($service, 'sleepBackoff');
        $method->setAccessible(true);

        $start = microtime(true);
        $method->invoke($service, 1000, 1);
        $elapsedMs = (microtime(true) - $start) * 1000;

        // Base 1000ms with ±20% jitter → 800–1200ms; wide assertion bounds.
        $this->assertGreaterThanOrEqual(750, $elapsedMs);
        $this->assertLessThanOrEqual(1400, $elapsedMs);
    }

    // ---- Connection-test token budget (reasoning models) ----

    public function test_connection_test_no_longer_sends_a_tiny_token_budget(): void
    {
        $this->seedProvider();
        Http::fake([
            self::ENDPOINT => Http::response($this->contentResponse('OK')),
        ]);

        $result = app(AiProviderService::class)->testConnection();

        $this->assertTrue($result['success'], $result['message']);

        Http::assertSent(fn ($request) => $request['max_tokens'] === 1024);
        Http::assertSent(fn ($request) => $request['max_tokens'] !== 20 && $request['max_tokens'] !== 10);
    }

    public function test_reasoning_model_passes_test_connection(): void
    {
        $this->seedProvider();

        // GPT-OSS shape: reasoning present, finish_reason=stop, visible OK.
        Http::fake([
            self::ENDPOINT => Http::response([
                'choices' => [['message' => ['content' => 'OK', 'reasoning' => 'brief reasoning…'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 60],
            ]),
        ]);

        $result = app(AiProviderService::class)->testConnection();

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringContainsString('OK', $result['message']);
    }

    public function test_empty_visible_content_still_fails_test_connection(): void
    {
        $this->seedProvider();

        // Even with the reasoning-safe budget, a model that spends it all
        // on reasoning without visible content must fail — HTTP 200 alone
        // is never success.
        Http::fake([
            self::ENDPOINT => Http::sequence()
                ->push($this->exhaustedResponse('all reasoning, no content'))
                ->push($this->exhaustedResponse('still all reasoning')),
        ]);

        $result = app(AiProviderService::class)->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('finish_reason=length', $result['message']);
        $this->assertStringContainsString('no visible assistant content', $result['message']);
        $this->assertStringContainsString('reasoning=present', $result['message']);
    }

    public function test_admin_output_token_setting_is_respected_by_connection_test(): void
    {
        $this->seedProvider(['max_tokens' => 8000]);
        Http::fake([
            self::ENDPOINT => Http::response($this->contentResponse('OK')),
        ]);

        app(AiProviderService::class)->testConnection();

        Http::assertSent(fn ($request) => $request['max_tokens'] === 8000);
    }

    public function test_model_probe_uses_the_reasoning_safe_budget(): void
    {
        $this->seedProvider();

        // No listing endpoint → the service falls back to a direct probe.
        Http::fake([
            'https://api.groq.com/openai/v1/models' => Http::response('', 404),
            self::ENDPOINT => Http::response($this->contentResponse('OK')),
        ]);

        $result = app(AiProviderService::class)->modelAvailability();

        $this->assertTrue($result['success'], $result['message']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions')
            && ($request->data()['max_tokens'] ?? null) === 1024);
    }
}
