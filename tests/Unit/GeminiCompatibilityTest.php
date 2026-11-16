<?php

namespace Tests\Unit;

use App\Models\AiSetting;
use App\Services\Ai\AiProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies Google Gemini's OpenAI-compatible API works through the existing
 * provider abstraction, per https://ai.google.dev/gemini-api/docs/openai:
 * POST {base}/chat/completions with Authorization: Bearer <API key>,
 * model listing via GET {base}/models, OpenAI-style response bodies.
 */
class GeminiCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/openai/';

    protected function seedGemini(array $overrides = []): AiSetting
    {
        return AiSetting::create($overrides + [
            'provider' => 'google-gemini',
            'endpoint' => self::ENDPOINT,
            'api_key' => 'AIzaSyFakeKeyForUnitTests000000',
            'auth_mode' => 'bearer',
            'model' => 'gemini-3.8-flash',
            'is_active' => true,
            'retry_backoff_ms' => 0,
            'max_retries' => 2,
        ]);
    }

    public function test_gemini_request_url_and_bearer_header_match_google_docs(): void
    {
        $this->seedGemini();
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => 'OK']]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3],
        ])]);

        $reply = app(AiProviderService::class)->chat([['role' => 'user', 'content' => 'Hi']], maxTokens: 6000);

        $this->assertEquals('OK', $reply);

        Http::assertSent(function ($request) {
            $url = $request->url();

            return $url === 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'
                && $request->header('Authorization')[0] === 'Bearer AIzaSyFakeKeyForUnitTests000000'
                && str_contains($request->header('Content-Type')[0], 'application/json')
                && ! isset($request['api_key'])
                && parse_url($url, PHP_URL_QUERY) === null
                && $request['model'] === 'gemini-3.8-flash'
                && $request['max_tokens'] === 6000
                && isset($request['messages']);
        });
    }

    public function test_gemini_success_response_is_parsed_with_usage(): void
    {
        $this->seedGemini();
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-x',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Gemini replied']]],
            'usage' => ['prompt_tokens' => 42, 'completion_tokens' => 17, 'total_tokens' => 59],
        ])]);

        $result = app(AiProviderService::class)->chatWithUsage([['role' => 'user', 'content' => 'Hi']]);

        $this->assertEquals('Gemini replied', $result['content']);
        $this->assertEquals(['prompt_tokens' => 42, 'completion_tokens' => 17, 'total_tokens' => 59], $result['usage']);
    }

    public function test_gemini_401_maps_to_authentication_hint(): void
    {
        $this->seedGemini();
        Http::fake(['*' => Http::response(['error' => ['code' => 401, 'message' => 'Invalid API key']], 401)]);

        $result = app(AiProviderService::class)->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Authentication failed', $result['message']);
        $this->assertStringContainsString('401', $result['message']);
        $this->assertStringNotContainsString('AIzaSyFakeKey', $result['message'], 'Credentials must never appear in messages.');
    }

    public function test_gemini_404_maps_to_not_found_hint(): void
    {
        $this->seedGemini(['model' => 'not-a-real-model']);
        Http::fake(['*' => Http::response(['error' => ['code' => 404, 'message' => 'models not found']], 404)]);

        $result = app(AiProviderService::class)->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Not found (HTTP 404)', $result['message']);
    }

    public function test_gemini_403_maps_to_permission_hint(): void
    {
        $this->seedGemini();
        Http::fake(['*' => Http::response(['error' => ['code' => 403, 'message' => 'Permission denied']], 403)]);

        $result = app(AiProviderService::class)->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Access denied (HTTP 403)', $result['message']);
    }

    public function test_gemini_429_is_rate_limited_hint_after_retries(): void
    {
        $this->seedGemini();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['error' => ['code' => 429, 'message' => 'Resource exhausted']], 429);
        });

        $result = app(AiProviderService::class)->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Rate limited (HTTP 429)', $result['message']);
        $this->assertEquals(3, $calls, '429 must be retried max_retries times before failing.');
    }

    public function test_gemini_models_listing_reports_configured_model_available(): void
    {
        $this->seedGemini();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/models' => Http::response([
                'object' => 'list',
                'data' => [
                    ['id' => 'gemini-3.8-flash', 'object' => 'model'],
                    ['id' => 'gemini-3.8-pro', 'object' => 'model'],
                ],
            ]),
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ]),
        ]);

        $result = app(AiProviderService::class)->modelAvailability();

        $this->assertTrue($result['success'], $result['message']);
        $this->assertContains('gemini-3.8-flash', $result['models']);
        $this->assertStringContainsString('verified callable', $result['message']);
    }

    public function test_gemini_models_prefix_is_normalized_when_matching(): void
    {
        // Gemini's live listing returns ids like "models/gemini-2.5-flash";
        // a configured name without the prefix must still match.
        $this->seedGemini(['model' => 'gemini-2.5-flash']);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/models' => Http::response([
                'object' => 'list',
                'data' => [
                    ['id' => 'models/gemini-2.5-flash'],
                    ['id' => 'models/gemini-2.5-pro'],
                ],
            ]),
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ]),
        ]);

        $result = app(AiProviderService::class)->modelAvailability();

        $this->assertTrue($result['success'], $result['message']);
        $this->assertContains('gemini-2.5-flash', $result['models'], 'Displayed names must be cleaned of the models/ prefix.');
        $this->assertNotContains('models/gemini-2.5-flash', $result['models']);
    }

    public function test_similar_but_different_model_names_do_not_match(): void
    {
        $this->seedGemini(['model' => 'gemini-2.5-flash']);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/models' => Http::response([
                'object' => 'list',
                'data' => [['id' => 'models/gemini-2.5-flash-lite'], ['id' => 'models/gemini-2.5-pro']],
            ]),
        ]);

        $result = app(AiProviderService::class)->modelAvailability();

        $this->assertFalse($result['success'], 'A similar but different model must not be reported as available.');
        $this->assertStringContainsString('was not found', $result['message']);
    }

    public function test_listed_but_not_callable_model_is_reported_accurately(): void
    {
        // Observed live: Google lists deprecated models that fail generation.
        $this->seedGemini(['model' => 'gemini-2.5-flash']);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/models' => Http::response([
                'object' => 'list',
                'data' => [['id' => 'models/gemini-2.5-flash']],
            ]),
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::response([
                'error' => ['code' => 404, 'message' => 'This model models/gemini-2.5-flash is no longer available'],
            ], 404),
        ]);

        $result = app(AiProviderService::class)->modelAvailability();

        $this->assertFalse($result['success'], 'A listed model that cannot generate must not be reported as available.');
        $this->assertStringContainsString('listed but not callable', $result['message']);
        $this->assertStringContainsString('no longer available', $result['message']);
    }

    public function test_empty_model_list_reports_none_listed(): void
    {
        $this->seedGemini();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/models' => Http::response(['object' => 'list']),
        ]);

        $result = app(AiProviderService::class)->modelAvailability();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('none listed', $result['message']);
    }

    public function test_malformed_model_list_is_handled_safely(): void
    {
        $this->seedGemini();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/models' => Http::response('not json at all'),
        ]);

        $result = app(AiProviderService::class)->modelAvailability();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('none listed', $result['message']);
    }

    public function test_gemini_models_listing_401_reports_auth_problem(): void
    {
        $this->seedGemini();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/models' => Http::response('Unauthorized', 401),
        ]);

        $result = app(AiProviderService::class)->modelAvailability();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('authentication was rejected', $result['message']);
    }

    public function test_missing_models_endpoint_falls_back_to_minimal_model_probe(): void
    {
        $this->seedGemini();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/models' => Http::response('Not Found', 404),
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ]),
        ]);

        $result = app(AiProviderService::class)->modelAvailability();

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringContainsString('minimal direct request', $result['message']);
    }

    public function test_model_probe_failure_reports_unavailable_model(): void
    {
        $this->seedGemini(['model' => 'gemini-nonexistent']);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/models' => Http::response('Not Found', 404),
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::response(['error' => ['code' => 404]], 404),
        ]);

        $result = app(AiProviderService::class)->modelAvailability();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('could not be verified', $result['message']);
    }

    public function test_successful_http_response_without_content_is_not_success(): void
    {
        $this->seedGemini();
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '']]],
        ])]);

        $result = app(AiProviderService::class)->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no visible assistant content', $result['message']);
        $this->assertStringContainsString('finish_reason=', $result['message']);
    }

    public function test_gemini_503_overload_maps_to_overloaded_hint_with_provider_message(): void
    {
        // Observed live: Google returns HTTP 503 "This model is currently
        // experiencing high demand" for gemini-3.8-flash under load.
        $this->seedGemini();
        Http::fake(['*' => Http::response([
            'error' => ['code' => 503, 'message' => 'This model is currently experiencing high demand. Spikes in demand are usually temporary. Please try again later.'],
        ], 503)]);

        $result = app(AiProviderService::class)->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Provider overloaded (HTTP 503)', $result['message']);
        $this->assertStringContainsString('high demand', $result['message']);
        $this->assertStringNotContainsString('[{"error"', $result['message'], 'Raw JSON must not be dumped to the admin.');
    }

    public function test_provider_error_message_is_extracted_from_json_body(): void
    {
        $this->seedGemini();
        Http::fake(['*' => Http::response([
            'error' => ['code' => 400, 'message' => 'The requested model is not supported by this API key.'],
        ], 400)]);

        $result = app(AiProviderService::class)->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The requested model is not supported by this API key.', $result['message']);
        $this->assertStringNotContainsString('{"error"', $result['message'], 'Raw JSON must not be dumped to the admin.');
    }

    public function test_missing_authorization_header_maps_to_auth_hint(): void
    {
        // Google's compat layer returns HTTP 400 "Missing or invalid
        // Authorization header." when the bearer header is absent — observed
        // live during verification.
        $this->seedGemini();
        Http::fake(['*' => Http::response([
            'error' => ['code' => 400, 'message' => 'Missing or invalid Authorization header.', 'status' => 'INVALID_ARGUMENT'],
        ], 400)]);

        $result = app(AiProviderService::class)->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Authentication problem', $result['message']);
        $this->assertStringContainsString('Re-enter the API key', $result['message']);
    }

    public function test_configuration_state_distinguishes_disabled_from_incomplete(): void
    {
        $service = app(AiProviderService::class);

        // No row at all.
        $state = $service->configurationState();
        $this->assertFalse($state['exists']);
        $this->assertFalse($state['configured']);

        // Complete but disabled.
        $this->seedGemini(['is_active' => false]);
        $state = $service->configurationState();
        $this->assertTrue($state['configured']);
        $this->assertFalse($state['enabled']);
        $this->assertSame([], $state['missing']);
    }
}
