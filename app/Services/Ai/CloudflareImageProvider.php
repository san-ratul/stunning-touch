<?php

namespace App\Services\Ai;

use App\Models\BlogPost;
use App\Models\ImageGenerationUsage;
use App\Models\ImageSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cloudflare Workers AI text-to-image client (FLUX.1 [schnell] family).
 *
 * Endpoint is always constructed server-side from the saved Account ID —
 * never accepted from browser input. The API token is decrypted from the
 * encrypted settings row, sent as a Bearer header, and never logged or
 * rendered.
 *
 * Response handling is defensive per the official docs
 * (https://developers.cloudflare.com/workers-ai/models/flux-1-schnell/):
 * JSON mode returns {"result": {"image": "<base64>"}}, but binary and
 * error envelopes are handled too. Every attempt (success or failure) is
 * recorded for quota accounting; only successes consume the daily limit.
 */
class CloudflareImageProvider
{
    public const DEFAULT_MODEL = '@cf/black-forest-labs/flux-1-schnell';

    protected const API_BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * True when the Cloudflare provider is selected and has account ID,
     * token and model configured.
     */
    public function isConfigured(ImageSetting $settings): bool
    {
        return $settings->isCloudflare()
            && filled($settings->account_id)
            && filled($settings->api_key)
            && filled($settings->model);
    }

    /**
     * @return array{ok: bool, bytes: ?string, mime: ?string, message: ?string, status: ?int}
     */
    public function generate(string $prompt, ?BlogPost $post = null): array
    {
        $settings = ImageSetting::current();

        if (! $this->isConfigured($settings)) {
            return ['ok' => false, 'bytes' => null, 'mime' => null, 'message' => 'Cloudflare image generation is not configured.', 'status' => null];
        }

        $usage = $this->usageToday();

        if ($usage['remaining'] <= 0) {
            return ['ok' => false, 'bytes' => null, 'mime' => null, 'message' => "Daily image limit reached ({$usage['success']}/{$usage['limit']}); using the local cover.", 'status' => null];
        }

        $url = $this->runUrl($settings);
        $attempts = 1 + max(0, (int) ($settings->max_retries ?? 2));
        $lastError = 'unknown error';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout((int) ($settings->timeout_seconds ?? 120))
                    ->connectTimeout(10)
                    ->withToken((string) $settings->api_key)
                    ->accept('application/json')
                    ->post($url, $this->payload($settings, $prompt));
            } catch (ConnectionException $e) {
                $lastError = 'Connection failed: '.Str::limit($e->getMessage(), 160);

                if ($attempt >= $attempts) {
                    $this->recordUsage(false, $lastError, $post, $settings);

                    return ['ok' => false, 'bytes' => null, 'mime' => null, 'message' => $lastError, 'status' => null];
                }

                $this->sleepBackoff($attempt);

                continue;
            }

            $status = $response->status();

            if ($status === 429 || $status >= 500) {
                $lastError = $this->describeStatus($status, $response);

                if ($attempt >= $attempts) {
                    $this->recordUsage(false, $lastError, $post, $settings);

                    return ['ok' => false, 'bytes' => null, 'mime' => null, 'message' => $lastError, 'status' => $status];
                }

                Log::info('Cloudflare image generation transient failure, retrying', ['status' => $status, 'attempt' => $attempt]);
                $this->sleepBackoff($attempt);

                continue;
            }

            if ($status >= 400) {
                $lastError = $this->describeStatus($status, $response);
                $this->recordUsage(false, $lastError, $post, $settings);

                return ['ok' => false, 'bytes' => null, 'mime' => null, 'message' => $lastError, 'status' => $status];
            }

            $parsed = $this->extractImage($response);

            if (! $parsed['ok']) {
                $this->recordUsage(false, $parsed['message'], $post, $settings);

                return ['ok' => false, 'bytes' => null, 'mime' => null, 'message' => $parsed['message'], 'status' => $status];
            }

            $this->recordUsage(true, null, $post, $settings);

            return ['ok' => true, 'bytes' => $parsed['bytes'], 'mime' => $parsed['mime'], 'message' => null, 'status' => $status];
        }

        $this->recordUsage(false, $lastError, $post, $settings);

        return ['ok' => false, 'bytes' => null, 'mime' => null, 'message' => $lastError, 'status' => null];
    }

    /**
     * Verify the saved token with Cloudflare's token-verify endpoint.
     * Uses only the token — never echoes credentials.
     */
    public function testConnection(): array
    {
        $settings = ImageSetting::current();

        if (! $this->isConfigured($settings)) {
            $missing = [];
            if (! $settings->isCloudflare()) {
                $missing[] = 'provider must be set to "Cloudflare"';
            }
            if (blank($settings->account_id)) {
                $missing[] = 'Account ID';
            }
            if (blank($settings->api_key)) {
                $missing[] = 'API token';
            }
            if (blank($settings->model)) {
                $missing[] = 'Model';
            }

            return ['ok' => false, 'message' => 'Cloudflare is not fully configured. Missing: '.implode(', ', $missing).'.'];
        }

        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->withToken((string) $settings->api_key)
                ->get(self::API_BASE.'/user/tokens/verify');
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => 'Could not reach Cloudflare: '.Str::limit($e->getMessage(), 160)];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ['ok' => false, 'message' => 'Authentication failed (HTTP '.$response->status().'): the API token was rejected. Re-enter the token.'];
        }

        if ($response->status() === 429) {
            return ['ok' => false, 'message' => 'Rate limited (HTTP 429): try again shortly.'];
        }

        if ($response->failed()) {
            return ['ok' => false, 'message' => 'Token verification failed (HTTP '.$response->status().').'];
        }

        $body = $response->json();

        if (($body['success'] ?? null) !== true) {
            $first = $body['errors'][0] ?? null;
            $detail = is_array($first) ? ($first['message'] ?? '') : '';

            return ['ok' => false, 'message' => 'Token verification rejected: '.Str::limit((string) $detail, 160)];
        }

        $status = $body['result']['status'] ?? '';

        return ['ok' => $status === 'active', 'message' => $status === 'active'
            ? 'Cloudflare token verified (active).'
            : 'Cloudflare token responded with status "'.Str::limit((string) $status, 40).'" — it may not be usable.'];
    }

    /**
     * @return array{success: int, failed: int, limit: int, remaining: int}
     */
    public function usageToday(): array
    {
        $settings = ImageSetting::current();
        $limit = max(0, (int) ($settings->daily_limit ?? 4));
        $today = now('UTC')->toDateString();

        $counts = ImageGenerationUsage::query()
            ->where('provider', 'cloudflare')
            ->whereDate('quota_date', $today)
            ->selectRaw("sum(case when status = 'success' then 1 else 0 end) as success")
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->first();

        $success = (int) ($counts?->success ?? 0);

        return [
            'success' => $success,
            'failed' => (int) ($counts?->failed ?? 0),
            'limit' => $limit,
            'remaining' => max(0, $limit - $success),
        ];
    }

    /**
     * Latest failed attempts for monitoring (sanitized messages only).
     */
    public function recentFailures(int $limit = 5): array
    {
        return ImageGenerationUsage::query()
            ->where('provider', 'cloudflare')
            ->where('status', 'failed')
            ->latest('id')
            ->limit($limit)
            ->get(['error_message', 'created_at'])
            ->map(fn ($row) => [
                'message' => Str::limit((string) $row->error_message, 180),
                'at' => $row->created_at?->format('M j, H:i'),
            ])
            ->all();
    }

    public function lastSuccessAt(): ?string
    {
        $last = ImageGenerationUsage::query()
            ->where('provider', 'cloudflare')
            ->where('status', 'success')
            ->latest('id')
            ->first(['created_at']);

        return $last?->created_at?->format('M j, Y H:i').' UTC';
    }

    public function recordUsage(bool $success, ?string $error, ?BlogPost $post, ?ImageSetting $settings = null): void
    {
        try {
            ImageGenerationUsage::query()->create([
                'provider' => 'cloudflare',
                'model' => $settings?->model,
                'blog_post_id' => $post?->id,
                'status' => $success ? 'success' : 'failed',
                'error_message' => $error,
                'quota_date' => now('UTC')->toDateString(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Image usage tracking failed: '.Str::limit($e->getMessage(), 160));
        }
    }

    protected function runUrl(ImageSetting $settings): string
    {
        // The model is validated server-side against a strict character set
        // ([a-z0-9@/._-]) and used raw, matching the official API examples.
        $model = $this->normalizedModel($settings);

        return self::API_BASE.'/accounts/'.((string) $settings->account_id).'/ai/run/'.$model;
    }

    protected function normalizedModel(ImageSetting $settings): string
    {
        return (string) ($settings->model ?: self::DEFAULT_MODEL);
    }

    /**
     * FLUX.1 [schnell] accepts prompt and step count; dimensions are not
     * requested from the API — the app crops/resizes locally to the
     * featured-image ratio instead of asking for unsupported sizes.
     *
     * @return array<string, mixed>
     */
    protected function payload(ImageSetting $settings, string $prompt): array
    {
        return [
            'prompt' => Str::limit($prompt, 1000),
            'steps' => min(8, max(1, (int) ($settings->steps ?? 4))),
        ];
    }

    /**
     * @return array{ok: bool, bytes: ?string, mime: ?string, message: ?string}
     */
    protected function extractImage(Response $response): array
    {
        $contentType = (string) $response->header('Content-Type');

        // Binary image response.
        if (str_starts_with(strtolower($contentType), 'image/')) {
            return $this->validateImageBytes((string) $response->body());
        }

        $body = $response->json();

        if (! is_array($body)) {
            return ['ok' => false, 'bytes' => null, 'mime' => null, 'message' => 'Cloudflare returned an unreadable response body.'];
        }

        if (($body['success'] ?? null) === false) {
            $first = $body['errors'][0] ?? null;
            $detail = is_array($first)
                ? (($first['code'] ?? '').' '.($first['message'] ?? ''))
                : (string) ($body['messages'][0] ?? 'unknown error');

            return ['ok' => false, 'bytes' => null, 'mime' => null, 'message' => 'Cloudflare error: '.Str::limit(trim($detail), 200)];
        }

        // Defensive: the documented shape is result.image; tolerate close
        // variants instead of assuming one exact layout.
        $b64 = $body['result']['image'] ?? $body['image'] ?? ($body['result']['images'][0]['image'] ?? null);

        if (is_array($b64)) {
            $b64 = $b64['image'] ?? $b64['b64_json'] ?? null;
        }

        if (! is_string($b64) || $b64 === '') {
            return ['ok' => false, 'bytes' => null, 'mime' => null, 'message' => 'Cloudflare returned no image data.'];
        }

        // Strip a possible data-URI prefix.
        if (preg_match('/^data:image\/[a-z+]+;base64,/i', $b64)) {
            $b64 = substr($b64, (int) strpos($b64, ',') + 1);
        }

        $bytes = base64_decode($b64, true);

        if ($bytes === false) {
            return ['ok' => false, 'bytes' => null, 'mime' => null, 'message' => 'Cloudflare image payload could not be decoded.'];
        }

        return $this->validateImageBytes($bytes);
    }

    /**
     * @return array{ok: bool, bytes: ?string, mime: ?string, message: ?string}
     */
    protected function validateImageBytes(string $bytes): array
    {
        if (strlen($bytes) < 64) {
            return ['ok' => false, 'bytes' => null, 'mime' => null, 'message' => 'Cloudflare image data is too small to be a valid image.'];
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return ['ok' => false, 'bytes' => null, 'mime' => null, 'message' => 'Cloudflare response is not a valid image.'];
        }

        return ['ok' => true, 'bytes' => $bytes, 'mime' => $info['mime'], 'message' => null];
    }

    protected function describeStatus(int $status, Response $response): string
    {
        $detail = Str::limit($response->json('errors.0.message') ?? $response->body(), 180);

        return match (true) {
            $status === 401 || $status === 403 => "Authentication failed (HTTP {$status}): the Cloudflare API token was rejected. {$detail}",
            $status === 429 => "Rate limited (HTTP 429): Cloudflare throttled the request after retries. {$detail}",
            $status >= 500 => "Provider error (HTTP {$status}): Cloudflare failed server-side. {$detail}",
            default => "Cloudflare API error (HTTP {$status}): {$detail}",
        };
    }

    protected function sleepBackoff(int $attempt): void
    {
        usleep(1000 * 1000 * (2 ** ($attempt - 1)));
    }
}
