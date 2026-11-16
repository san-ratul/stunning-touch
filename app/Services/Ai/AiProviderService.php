<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Support\EndpointGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OpenAI-compatible and Ollama-native chat-completions client.
 *
 * Provider settings live in the database (credentials stored encrypted);
 * credentials are never hardcoded, logged, or exposed. The service
 * degrades gracefully, retries transient failures with exponential
 * backoff, normalizes token usage across providers, and never invents
 * credentials or fabricates research data.
 *
 * Ollama support:
 * - OpenAI-compatible endpoints (…/v1/chat/completions) work as-is.
 * - Native Ollama endpoints (…/api/chat) receive options.num_ctx and
 *   keep_alive, with usage read from prompt_eval_count / eval_count.
 */
class AiProviderService
{
    public function settings(): ?AiSetting
    {
        return AiSetting::query()->where('is_active', true)->first();
    }

    public function isConfigured(): bool
    {
        $settings = $this->settings();

        if ($settings === null || blank($settings->model) || blank($settings->endpoint)) {
            return false;
        }

        // API keys are only mandatory for bearer authentication; local or
        // proxy-authenticated Ollama endpoints may run without any key.
        if (($settings->auth_mode ?? 'bearer') === 'bearer' && blank($settings->api_key)) {
            return false;
        }

        return true;
    }

    /**
     * Authoritative configuration state used by both the admin UI and the
     * service so they can never disagree about whether the provider works.
     *
     * "configured" means complete (all fields for the chosen auth mode are
     * present); it is independent of the enabled flag.
     *
     * @return array{exists: bool, enabled: bool, configured: bool, missing: list<string>, auth_mode: string, has_key: bool}
     */
    public function configurationState(): array
    {
        $settings = AiSetting::primary();

        if ($settings === null) {
            return [
                'exists' => false,
                'enabled' => false,
                'configured' => false,
                'missing' => ['API endpoint', 'model', 'API key'],
                'auth_mode' => 'bearer',
                'has_key' => false,
            ];
        }

        $missing = [];

        if (blank($settings->endpoint)) {
            $missing[] = 'API endpoint';
        }

        if (blank($settings->model)) {
            $missing[] = 'model';
        }

        $authMode = (string) ($settings->auth_mode ?? 'bearer');
        $hasKey = filled($settings->api_key);

        if ($authMode === 'bearer' && ! $hasKey) {
            $missing[] = 'API key';
        }

        if ($authMode === 'basic' && blank($settings->basic_auth_user)) {
            $missing[] = 'basic auth user';
        }

        return [
            'exists' => true,
            'enabled' => (bool) $settings->is_active,
            'configured' => $missing === [],
            'missing' => $missing,
            'auth_mode' => $authMode,
            'has_key' => $hasKey,
        ];
    }

    /**
     * Human-readable provider error detail. OpenAI-compatible error bodies
     * carry the message at error.message — prefer it over raw JSON.
     */
    protected function providerErrorDetail(Response $response): string
    {
        $message = $response->json('error.message');

        if (filled($message)) {
            return Str::limit((string) $message, 200);
        }

        return Str::limit($response->body(), 200);
    }

    /**
     * Map a transport/API failure to a sanitized, actionable message.
     * Never includes credentials or request headers.
     */
    protected function describeFailure(string $detail): string
    {
        $hints = [
            '401' => 'Authentication failed (HTTP 401): the API key was rejected. Re-enter the key in AI Provider settings.',
            '403' => 'Access denied (HTTP 403): the key lacks permission for this endpoint or model.',
            '404' => 'Not found (HTTP 404): check the endpoint URL and the configured model name.',
            '429' => 'Rate limited (HTTP 429): the provider throttled the request after retries. Try again later.',
            '500' => 'Provider error (HTTP 500): the API failed server-side. Try again later.',
            '502' => 'Provider error (HTTP 502): the API gateway failed. Try again later.',
            '503' => 'Provider overloaded (HTTP 503): the model is temporarily at capacity or in high demand. Try again in a few minutes.',
            '504' => 'Provider timeout (HTTP 504): the API took too long upstream. Try again later.',
        ];

        foreach ($hints as $status => $hint) {
            if (str_contains($detail, "error {$status}") || str_contains($detail, "HTTP {$status}")) {
                return $hint.' '.Str::limit($detail, 160);
            }
        }

        if (str_contains(strtolower($detail), 'authorization')) {
            return 'Authentication problem: the provider rejected the Authorization header. Re-enter the API key in AI Provider settings. '.Str::limit($detail, 140);
        }

        return 'Connection failed: '.Str::limit($detail, 200);
    }

    /**
     * Token budget for connection-test and model-probe requests.
     *
     * Reasoning models (e.g. Groq GPT-OSS) consume completion tokens for
     * invisible internal reasoning before writing any visible content — the
     * old hardcoded 10-token probe (doubled once to 20 by the budget retry)
     * failed every reasoning model with finish_reason=length even when the
     * connection and model were perfectly healthy.
     *
     * The budget is a LIMIT, not consumption: the short deterministic
     * "Reply with exactly: OK" prompt still costs only a handful of tokens
     * for non-reasoning models. The admin-configured "Max output tokens"
     * is reused when set; the floor is 1024 so reasoning models always have
     * headroom to produce visible output.
     */
    public function connectionProbeTokenBudget(?AiSetting $settings = null): int
    {
        return (int) ($settings?->max_tokens ?? 0) ?: 1024;
    }

    public function testConnection(): array
    {
        $state = $this->configurationState();
        $settings = AiSetting::primary();

        if (! $state['exists']) {
            return ['success' => false, 'message' => 'AI provider is not saved yet. Fill in endpoint, model and credentials, then save.'];
        }

        if (! $state['enabled']) {
            return ['success' => false, 'message' => 'AI provider settings are saved but disabled. Tick "Enable AI content generation" and save to activate the provider.'];
        }

        if (! $state['configured']) {
            return ['success' => false, 'message' => 'AI provider is incomplete. Missing: '.implode(', ', $state['missing']).'.'];
        }

        try {
            $result = $this->chatWithUsage(
                [['role' => 'user', 'content' => 'Reply with exactly: OK']],
                maxTokens: $this->connectionProbeTokenBudget($settings),
                temperature: 0,
                timeoutSeconds: 60
            );

            return ['success' => true, 'message' => 'Connection successful. Provider responded: '.Str::limit(trim((string) $result['content']), 80)];
        } catch (\Throwable $e) {
            Log::warning('AI connection test failed: '.$e->getMessage());

            $message = $e->getMessage();

            // An HTTP 200 with empty assistant content is not a connection
            // problem — surface the precise output-budget diagnostic as-is.
            if (str_starts_with($message, 'AI returned an empty response')) {
                return ['success' => false, 'message' => $message];
            }

            return ['success' => false, 'message' => $this->describeFailure($message)];
        }
    }

    /**
     * Check whether the configured model exists on the provider.
     * Queries /api/tags on Ollama native endpoints and /models on
     * OpenAI-compatible endpoints (Gemini exposes /v1beta/openai/models).
     * When a provider has no listing endpoint, falls back to a minimal
     * model-specific chat probe instead of reporting a working model as
     * unavailable.
     */
    public function modelAvailability(): array
    {
        $settings = $this->settings();

        if (! $settings || blank($settings->endpoint) || blank($settings->model)) {
            return ['success' => false, 'message' => 'Configure endpoint and model first.'];
        }

        $native = $this->isOllamaNative((string) $settings->endpoint);
        $url = $this->baseUrl(rtrim((string) $settings->endpoint, '/')).($native ? '/api/tags' : '/models');

        try {
            $response = $this->authorizedClient($settings, 30)->get($url);
        } catch (ConnectionException $e) {
            return ['success' => false, 'message' => 'Could not reach provider: '.Str::limit($e->getMessage(), 160)];
        }

        if ($response->failed()) {
            $status = $response->status();

            if ($status === 401 || $status === 403) {
                return ['success' => false, 'message' => "Model listing failed: authentication was rejected (HTTP {$status}). Re-check the stored API key."];
            }

            if ($status === 429) {
                return ['success' => false, 'message' => 'Model listing failed: rate limited (HTTP 429). Try again shortly.'];
            }

            if ($status === 404 && ! $native) {
                // Provider exposes no listing endpoint — verify the model
                // directly with a minimal, cheap chat request.
                $probe = $this->probeModel($settings);

                return $probe['success']
                    ? [
                        'success' => true,
                        'message' => 'Model "'.$settings->model.'" is callable (verified by a minimal direct request; this provider does not expose a model-listing endpoint).',
                        'models' => [],
                    ]
                    : [
                        'success' => false,
                        'message' => 'Model "'.$settings->model.'" could not be verified ('.$probe['detail'].').',
                        'models' => [],
                    ];
            }

            return ['success' => false, 'message' => 'Model listing failed (HTTP '.$status.').'];
        }

        $models = $native ? ($response->json('models') ?? []) : ($response->json('data') ?? []);

        // Gemini's OpenAI-compatible listing returns ids prefixed with
        // "models/" (e.g. models/gemini-2.5-flash). Normalize both sides so
        // the two spellings are recognized as equivalent identifiers. The
        // normalization is only for comparison and display — the chat
        // completion request always uses the originally configured ID.
        $stripPrefix = fn ($name) => preg_replace('/^models\//i', '', (string) $name);

        $names = collect(is_array($models) ? $models : [])
            ->map(fn ($m) => is_array($m) ? ($m['name'] ?? $m['id'] ?? null) : null)
            ->filter()
            ->map($stripPrefix)
            ->values();

        $requested = $stripPrefix((string) $settings->model);

        // Exact match after normalization — deliberately no loose prefix
        // matching, so "gemini-2.5-flash" never matches "gemini-2.5-flash-lite".
        $available = $names->contains(fn ($n) => strcasecmp((string) $n, $requested) === 0);

        if (! $available) {
            return [
                'success' => false,
                'message' => 'Model "'.$requested.'" was not found. Available models: '.($names->isEmpty() ? 'none listed' : $names->take(10)->implode(', ')),
                'models' => $names->all(),
            ];
        }

        // Listing presence does not guarantee callability: Google's listing
        // can include deprecated models that fail on generation. Verify with
        // a minimal direct request (OpenAI-compatible providers only — for
        // native Ollama, /api/tags already reflects installed models).
        if (! $native) {
            $probe = $this->probeModel($settings);

            if (! $probe['success']) {
                return [
                    'success' => false,
                    'message' => 'Model "'.$requested.'" is listed but not callable ('.$probe['detail'].'). Choose a callable model from the list.',
                    'models' => $names->all(),
                ];
            }

            return [
                'success' => true,
                'message' => 'Model "'.$requested.'" is listed and verified callable (minimal generation probe succeeded).',
                'models' => $names->all(),
            ];
        }

        return [
            'success' => true,
            'message' => 'Model "'.$requested.'" is installed on the Ollama server.',
            'models' => $names->all(),
        ];
    }

    /**
     * Verify the configured model with a minimal, cheap chat request.
     *
     * @return array{success: bool, detail: string}
     */
    protected function probeModel(AiSetting $settings): array
    {
        try {
            $reply = $this->chat(
                [['role' => 'user', 'content' => 'Reply with exactly: OK']],
                maxTokens: $this->connectionProbeTokenBudget($settings),
                temperature: 0,
                timeoutSeconds: 60
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'detail' => Str::limit($e->getMessage(), 240)];
        }

        if (blank($reply)) {
            return ['success' => false, 'detail' => 'the model responded with no assistant content'];
        }

        return ['success' => true, 'detail' => 'verified by a minimal direct request'];
    }

    /**
     * Run $callback while holding the global single-generation lock so only
     * one LLM request executes at a time on memory-constrained hosts.
     * Returns null immediately when another generation holds the lock.
     *
     * @return mixed callback result, or null when busy
     */
    public function withGenerationLock(callable $callback, int $ttlSeconds = 7200): mixed
    {
        $lock = Cache::lock('ai:generation', $ttlSeconds);

        if (! $lock->acquire()) {
            Log::info('AI generation skipped: another generation is already running.');

            return null;
        }

        try {
            return $callback();
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
                // Lock already expired or released; nothing to recover.
            }
        }
    }

    /**
     * @return string|null assistant text
     *
     * @throws \RuntimeException on transport/API failure
     */
    public function chat(array $messages, ?int $maxTokens = null, ?float $temperature = null, ?int $timeoutSeconds = null): ?string
    {
        return $this->chatWithUsage($messages, $maxTokens, $temperature, $timeoutSeconds)['content'];
    }

    /**
     * Chat completion that also returns normalized token usage so callers
     * can persist prompt/completion token accounting.
     *
     * @return array{content: string|null, usage: array{prompt_tokens: int|null, completion_tokens: int|null, total_tokens: int|null}|null}
     *
     * @throws \RuntimeException on transport/API failure
     */
    public function chatWithUsage(array $messages, ?int $maxTokens = null, ?float $temperature = null, ?int $timeoutSeconds = null): array
    {
        $settings = $this->settings();

        if (! $settings) {
            throw new \RuntimeException('AI provider is not configured.');
        }

        $endpoint = $this->requestUrl((string) $settings->endpoint);
        $payload = $this->buildPayload($settings, $messages, $maxTokens, $temperature);

        $attempts = 1 + max(0, (int) ($settings->max_retries ?? 0));
        $backoffBaseMs = max(0, (int) ($settings->retry_backoff_ms ?? 2000));
        // Output-budget ceiling for the controlled finish_reason=length
        // retry: at least 16000, or the admin-configured max output tokens
        // when higher. Deliberately bounded so reasoning models cannot push
        // every request into provider TPM rate limits.
        $maxOutputCeiling = max(16000, (int) ($settings->max_tokens ?? 0));
        $budgetRetryDone = false;
        $startedAt = microtime(true);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->authorizedClient($settings, $timeoutSeconds)
                    ->post($endpoint, $payload);
            } catch (ConnectionException $e) {
                if ($attempt >= $attempts) {
                    throw new \RuntimeException('AI connection failed after '.$attempts.' attempt(s): '.Str::limit($e->getMessage(), 200));
                }

                Log::warning('AI connection error, retrying', ['attempt' => $attempt, 'error' => Str::limit($e->getMessage(), 160)]);
                $this->sleepBackoff($backoffBaseMs, $attempt);

                continue;
            }

            $status = $response->status();

            if ($status === 429 || $status >= 500) {
                if ($attempt >= $attempts) {
                    throw new \RuntimeException('AI API error '.$status.' after '.$attempts.' attempt(s): '.$this->providerErrorDetail($response));
                }

                Log::warning('AI transient error, retrying', ['status' => $status, 'attempt' => $attempt]);
                $this->sleepBackoff($backoffBaseMs, $attempt, $this->retryAfterSeconds($response));

                continue;
            }

            if ($response->failed()) {
                throw new \RuntimeException('AI API error '.$status.': '.$this->providerErrorDetail($response));
            }

            $result = $this->extractResult($response);

            // Controlled continuation for reasoning models: an empty
            // response with finish_reason=length means the output budget was
            // exhausted (often by invisible reasoning). Retry exactly ONCE
            // with a doubled, capped budget — never an identical request,
            // never a loop, and the exhausted attempt's tokens are still
            // accounted for.
            if (! $budgetRetryDone
                && blank($result['content'])
                && ($result['diagnostics']['finish_reason'] ?? null) === 'length'
                && filled($payload['max_tokens'] ?? null)
                && (int) $payload['max_tokens'] < $maxOutputCeiling) {
                $budgetRetryDone = true;
                $exhaustedUsage = $result['usage'];
                $previousLimit = (int) $payload['max_tokens'];
                $payload['max_tokens'] = min($maxOutputCeiling, $previousLimit * 2);

                Log::warning('Model exhausted its output budget without visible content; retrying once with a larger budget', [
                    'provider' => $settings->provider,
                    'model' => $settings->model,
                    'previous_max_tokens' => $previousLimit,
                    'retry_max_tokens' => $payload['max_tokens'],
                ]);

                try {
                    $retryResponse = $this->authorizedClient($settings, $timeoutSeconds)->post($endpoint, $payload);
                } catch (ConnectionException $e) {
                    throw new \RuntimeException('AI connection failed during output-budget retry: '.Str::limit($e->getMessage(), 200));
                }

                if ($retryResponse->failed()) {
                    throw new \RuntimeException('AI API error '.$retryResponse->status().': '.$this->providerErrorDetail($retryResponse));
                }

                $result = $this->extractResult($retryResponse);
                $result['usage'] = $this->combineUsage($exhaustedUsage, $result['usage']);
            }

            if (blank($result['content'])) {
                throw new \RuntimeException($this->emptyOutputDiagnostic($result, $payload));
            }

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            Log::info('AI request completed', [
                'provider' => $settings->provider,
                'model' => $settings->model,
                'endpoint_host' => parse_url($endpoint, PHP_URL_HOST),
                'attempt' => $attempt,
                'duration_ms' => $durationMs,
                'prompt_tokens' => $result['usage']['prompt_tokens'] ?? null,
                'completion_tokens' => $result['usage']['completion_tokens'] ?? null,
            ]);

            return $result;
        }

        throw new \RuntimeException('AI request failed unexpectedly.');
    }

    /**
     * Combined token usage across an exhausted attempt and its retry so
     * billing-accurate accounting survives the internal budget retry.
     */
    protected function combineUsage(?array $first, ?array $second): ?array
    {
        if ($first === null) {
            return $second;
        }

        if ($second === null) {
            return $first;
        }

        $prompt = ($first['prompt_tokens'] ?? 0) + ($second['prompt_tokens'] ?? 0);
        $completion = ($first['completion_tokens'] ?? 0) + ($second['completion_tokens'] ?? 0);

        return [
            'prompt_tokens' => $prompt ?: null,
            'completion_tokens' => $completion ?: null,
            'total_tokens' => $prompt + $completion ?: null,
        ];
    }

    /**
     * Precise, safe diagnostic for an HTTP 200 response without visible
     * assistant content. Never dumps raw payloads or credentials.
     */
    protected function emptyOutputDiagnostic(array $result, array $payload): string
    {
        $diagnostics = $result['diagnostics'] ?? [];
        $finishReason = (string) ($diagnostics['finish_reason'] ?? 'unknown');
        $reasoning = (string) ($diagnostics['reasoning'] ?? 'unknown');
        $limit = $payload['max_tokens'] ?? 'provider default';

        $message = 'AI returned an empty response: the model produced no visible assistant content '
            .'(finish_reason='.$finishReason.', reasoning='.$reasoning.', output token limit='.$limit.').';

        if ($finishReason === 'length') {
            $message .= ' The model used its entire output budget before writing any visible content — typical for reasoning models whose internal reasoning consumes the completion budget. Increase "Max output tokens" in AI provider settings (e.g. 16000) or choose a non-reasoning model.';
        }

        return $message;
    }

    /**
     * Retry-After support: numeric seconds, or an HTTP-date converted to a
     * relative delay. Absent or unparseable values yield null.
     */
    protected function retryAfterSeconds(Response $response): ?int
    {
        $value = trim((string) (($response->header('Retry-After')[0] ?? null)));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    /**
     * Discover models from the configured provider's model-list endpoint.
     *
     * Called by the admin AI-settings UI. Overrides come from the (possibly
     * unsaved) form fields; stored encrypted credentials are used whenever
     * a field is blank. Submitted credentials are used for this one request
     * and never persisted or echoed.
     *
     * Response caps: at most 1000 models are returned, deduplicated and
     * sorted case-insensitively.
     *
     * @return array{success: bool, message: string, models: list<string>, auth_required?: bool}
     */
    public function discoverModels(
        ?string $endpointOverride = null,
        ?string $apiKeyOverride = null,
        ?string $authModeOverride = null,
        ?string $basicUser = null,
        ?string $basicPassword = null
    ): array {
        $settings = AiSetting::primary();

        $endpoint = filled($endpointOverride) ? trim((string) $endpointOverride) : (string) ($settings?->endpoint ?? '');

        if (blank($endpoint)) {
            return ['success' => false, 'message' => 'Enter an API endpoint first, then fetch models.', 'models' => []];
        }

        $guard = EndpointGuard::check($endpoint);
        if ($guard !== null) {
            return ['success' => false, 'message' => $guard, 'models' => []];
        }

        $authMode = (string) ($authModeOverride ?: ($settings?->auth_mode ?? 'bearer'));

        $apiKey = null;
        if ($authMode === 'bearer') {
            $apiKey = filled($apiKeyOverride) ? (string) $apiKeyOverride : (string) ($settings?->api_key ?? '');

            if (blank($apiKey)) {
                return [
                    'success' => false,
                    'auth_required' => true,
                    'message' => 'An API key is required for bearer authentication. Enter the key in the form, or choose Authentication "None" for keyless providers such as local Ollama.',
                    'models' => [],
                ];
            }
        }

        $basicUser = filled($basicUser) ? (string) $basicUser : (string) ($settings?->basic_auth_user ?? '');
        $basicPassword = filled($basicPassword) ? (string) $basicPassword : (string) ($settings?->basic_auth_password ?? '');

        $native = $this->isOllamaNative($endpoint);
        $listUrl = $this->baseUrl(rtrim($endpoint, '/')).($native ? '/api/tags' : '/models');

        $client = $this->authClient(
            Http::timeout(30)->connectTimeout(10),
            $authMode,
            $apiKey,
            $basicUser,
            $basicPassword
        );

        try {
            $response = $client->get($listUrl);
        } catch (ConnectionException $e) {
            Log::warning('Model discovery could not reach the provider', [
                'endpoint_host' => parse_url($listUrl, PHP_URL_HOST),
                'error' => Str::limit($e->getMessage(), 160),
            ]);

            return [
                'success' => false,
                'message' => 'Could not reach the provider (timeout or connection failure): '.Str::limit($e->getMessage(), 160),
                'models' => [],
            ];
        }

        $status = $response->status();

        if ($status === 401 || $status === 403) {
            return ['success' => false, 'message' => "Invalid or unauthorized API key (HTTP {$status}). Re-check the key and try again.", 'models' => []];
        }

        if ($status === 404) {
            return [
                'success' => false,
                'message' => 'Automatic model discovery is not supported by this provider. You can enter a model ID manually.',
                'models' => [],
            ];
        }

        if ($status === 429) {
            return ['success' => false, 'message' => 'The provider rate limited the request (HTTP 429). Try again shortly.', 'models' => []];
        }

        if ($response->failed()) {
            return ['success' => false, 'message' => 'The provider rejected the model list request (HTTP '.$status.').', 'models' => []];
        }

        $body = $response->json();

        if (! is_array($body)) {
            return ['success' => false, 'message' => 'The provider returned a malformed model list (not JSON).', 'models' => []];
        }

        $models = collect($native ? ($body['models'] ?? []) : ($body['data'] ?? []))
            ->map(fn ($m) => is_array($m)
                ? (string) ($m['id'] ?? $m['model'] ?? $m['name'] ?? '')
                : (string) $m)
            ->map(fn ($n) => trim($n))
            ->filter(fn ($n) => $n !== '' && mb_strlen($n) <= 200)
            ->unique()
            ->sortBy(null, SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->take(1000)
            ->all();

        if ($models === []) {
            return [
                'success' => false,
                'message' => 'The provider returned an empty model list. You can enter a model ID manually.',
                'models' => [],
            ];
        }

        Log::info('Model discovery succeeded', [
            'endpoint_host' => parse_url($listUrl, PHP_URL_HOST),
            'native_ollama' => $native,
            'count' => count($models),
        ]);

        return [
            'success' => true,
            'message' => 'Retrieved '.count($models).' model'.(count($models) === 1 ? '' : 's').'.',
            'models' => $models,
        ];
    }

    protected function extractResult(Response $response): array
    {
        $body = $response->json();

        // OpenAI-compatible shape first, then Ollama native shape.
        $text = $body['choices'][0]['message']['content']
            ?? $body['message']['content']
            ?? null;

        $usage = is_array($body['usage'] ?? null)
            ? $body['usage']
            : (is_array($body) ? $body : null);

        return [
            'content' => is_string($text) ? $text : null,
            'usage' => $this->normalizeUsage($usage),
            // Safe diagnostics for the "HTTP 200 but empty assistant content"
            // case: no credentials, no raw payload — just response shape.
            // Groq GPT-OSS returns reasoning in message.reasoning; other
            // providers use message.reasoning_content — both are reported
            // honestly and never treated as article content.
            'diagnostics' => is_array($body) ? [
                'model' => is_string($body['model'] ?? null) ? $body['model'] : null,
                'choices' => is_array($body['choices'] ?? null) ? count($body['choices']) : 0,
                'finish_reason' => $body['choices'][0]['finish_reason'] ?? null,
                'reasoning' => isset($body['choices'][0]['message']['reasoning']) || isset($body['choices'][0]['message']['reasoning_content']) ? 'present' : 'absent',
            ] : null,
        ];
    }

    /**
     * Normalize token usage across providers. OpenAI-compatible APIs use
     * prompt_tokens/completion_tokens; Ollama native uses
     * prompt_eval_count/eval_count.
     */
    public function normalizeUsage(?array $usage): ?array
    {
        if ($usage === null) {
            return null;
        }

        $prompt = $usage['prompt_tokens'] ?? $usage['prompt_eval_count'] ?? null;
        $completion = $usage['completion_tokens'] ?? $usage['eval_count'] ?? null;

        if (! is_numeric($prompt) && ! is_numeric($completion)) {
            return null;
        }

        $promptTokens = is_numeric($prompt) ? (int) $prompt : null;
        $completionTokens = is_numeric($completion) ? (int) $completion : null;

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => ($promptTokens ?? 0) + ($completionTokens ?? 0),
        ];
    }

    protected function authorizedClient(AiSetting $settings, ?int $timeoutSeconds = null): PendingRequest
    {
        $client = Http::timeout($timeoutSeconds ?? (int) ($settings->timeout_seconds ?? 900))
            ->connectTimeout((int) ($settings->connect_timeout_seconds ?? 15));

        return $this->authClient(
            $client,
            (string) ($settings->auth_mode ?? 'bearer'),
            blank($settings->api_key) ? null : (string) $settings->api_key,
            blank($settings->basic_auth_user) ? null : (string) $settings->basic_auth_user,
            blank($settings->basic_auth_password) ? null : (string) $settings->basic_auth_password
        );
    }

    /**
     * Apply the auth mode to a prepared client. Credentials are never
     * logged; the key never leaves the server.
     */
    protected function authClient(PendingRequest $client, string $authMode, ?string $apiKey, ?string $basicUser, ?string $basicPassword): PendingRequest
    {
        return match ($authMode) {
            'none' => $client,
            'basic' => $client->withBasicAuth((string) $basicUser, (string) $basicPassword),
            default => blank($apiKey) ? $client : $client->withToken($apiKey),
        };
    }

    protected function buildPayload(AiSetting $settings, array $messages, ?int $maxTokens, ?float $temperature): array
    {
        if ($this->isOllamaNative((string) $settings->endpoint)) {
            $payload = [
                'model' => $settings->model,
                'messages' => $messages,
                'stream' => false,
            ];

            $options = ['temperature' => $temperature ?? $settings->temperature ?? 0.7];

            if (filled($settings->context_length)) {
                $options['num_ctx'] = (int) $settings->context_length;
            }

            if ($maxTokens || $settings->max_tokens) {
                $options['num_predict'] = $maxTokens ?? $settings->max_tokens;
            }

            $payload['options'] = $options;

            if (filled($settings->keep_alive)) {
                $payload['keep_alive'] = (string) $settings->keep_alive;
            }

            return $payload;
        }

        $payload = [
            'model' => $settings->model,
            'messages' => $messages,
        ];

        $payload['temperature'] = $temperature ?? $settings->temperature ?? 0.7;

        // Token budget parameter: `max_tokens` is sent (verified live against
        // Groq — finish_reason=length honored the limit). Groq also accepts
        // the newer `max_completion_tokens`, but older OpenAI-compatible
        // providers only accept `max_tokens`, so the widely-supported
        // parameter is kept for all OpenAI-compatible endpoints.
        if ($maxTokens || $settings->max_tokens) {
            $payload['max_tokens'] = $maxTokens ?? $settings->max_tokens;
        }

        return $payload;
    }

    protected function requestUrl(string $endpoint): string
    {
        $endpoint = rtrim($endpoint, '/');

        if (! $this->isOllamaNative($endpoint) && ! str_ends_with($endpoint, '/chat/completions')) {
            $endpoint .= '/chat/completions';
        }

        return $endpoint;
    }

    protected function isOllamaNative(string $endpoint): bool
    {
        return str_contains($endpoint, '/api/chat');
    }

    protected function baseUrl(string $endpoint): string
    {
        foreach (['/chat/completions', '/api/chat', '/completions'] as $suffix) {
            if (str_ends_with($endpoint, $suffix)) {
                return substr($endpoint, 0, strlen($endpoint) - strlen($suffix));
            }
        }

        return $endpoint;
    }

    /**
     * Bounded exponential backoff with ±20% jitter. When the provider sent
     * a Retry-After header, the larger of the computed backoff and the
     * provider-requested delay is used (capped at 60s so admin requests
     * stay responsive). Provider-requested delays are never ignored.
     */
    protected function sleepBackoff(int $baseMs, int $attempt, ?int $retryAfterSeconds = null): void
    {
        $ms = $baseMs > 0
            ? (int) round($baseMs * (2 ** ($attempt - 1)) * (0.8 + (mt_rand() / mt_getrandmax()) * 0.4))
            : 0;

        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            $ms = max($ms, min($retryAfterSeconds * 1000, 60000));
        }

        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
