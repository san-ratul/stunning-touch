<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use App\Support\BotMetrics;
use App\Support\SeoResolver;
use App\Support\TrafficClassifier;
use App\Support\TrafficVerdict;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ThrottleRequestsException;

/**
 * Application-level bot protection: separate detection and enforcement.
 *
 * Detection runs for every relevant request; enforcement follows
 * endpoint-specific policies:
 *
 * - Humans pass untouched (no analytics writes, no limits added).
 * - Verified search crawlers may crawl GET pages; they are still
 *   rate-limited generously and never allowed to POST protected endpoints.
 * - Known automated clients may read pages under a per-IP rate limit and
 *   are blocked from protected POST endpoints.
 * - Suspicious automation (e.g. unverified crawler claims) is blocked from
 *   protected POST endpoints and rate-limited harder elsewhere.
 * - Unknown traffic is allowed (endpoint throttles still apply) and kept
 *   out of human visitor counts.
 *
 * Every request has at most one final enforcement outcome, and counters
 * are only written when the outcome actually happens.
 */
class BotGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->enabled()) {
            return $next($request);
        }

        $group = $this->endpointGroup($request);
        $verdict = null;

        try {
            // Admin area is authenticated; only the login attempt surface
            // is monitored. Health checks are never classified.
            $isAdmin = $request->is('admin/*') || $request->is('admin');

            if ($group === 'health' || ($isAdmin && $group !== 'login')) {
                return $next($request);
            }

            $verdict = TrafficClassifier::classify($request);

            // Contact honeypot: the request is rejected by the existing
            // validation (behaviour preserved); it is also counted as an
            // actual rejection of suspicious automation.
            if ($group === 'contact' && $request->isMethod('POST') && filled($request->input('website'))) {
                BotMetrics::record(TrafficClassifier::honeypotVerdict(), 'contact', BotMetrics::ACTION_BLOCKED);
                BotMetrics::markCounted($request, BotMetrics::ACTION_BLOCKED);
            }

            if ($verdict->isBot()) {
                $response = $this->handleBot($request, $next, $verdict, $group);

                if ($response instanceof Response) {
                    return $response;
                }
            } elseif ($verdict->category === TrafficVerdict::UNKNOWN && ! BotMetrics::isCounted($request)) {
                // Unknown is allowed everywhere (existing throttles still
                // apply) and counted so it is never mistaken for human.
                BotMetrics::record($verdict, $group, BotMetrics::ACTION_UNKNOWN_SEEN);
                BotMetrics::markCounted($request, BotMetrics::ACTION_UNKNOWN_SEEN);
            }
        } catch (\Throwable) {
            // Protection must never break the site; degrade to no-op.
            $verdict = null;
        }

        $response = null;

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // Inner middleware (e.g. throttle) may reject by exception; the
            // response is only produced by the handler outside this stack.
            if ($e instanceof ThrottleRequestsException && $verdict !== null && ! BotMetrics::isCounted($request)) {
                BotMetrics::recordNamed($verdict->category, (string) $verdict->botName, $group, BotMetrics::ACTION_RATE_LIMITED, 'rate-limit-endpoint');
                BotMetrics::markCounted($request, BotMetrics::ACTION_RATE_LIMITED);
            }

            throw $e;
        }

        try {
            // Any 429 produced by other rate limiters on this request is
            // counted exactly once, whichever client produced it.
            if ($response->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS
                && $verdict !== null
                && ! BotMetrics::isCounted($request)) {
                BotMetrics::recordNamed($verdict->category, (string) $verdict->botName, $group, BotMetrics::ACTION_RATE_LIMITED, 'rate-limit-endpoint');
                BotMetrics::markCounted($request, BotMetrics::ACTION_RATE_LIMITED);
            }
        } catch (\Throwable) {
        }

        return $response;
    }

    /**
     * Endpoint policy for automated traffic. Returns a Response only when
     * the request is actually rejected; null means "continue".
     */
    protected function handleBot(Request $request, Closure $next, TrafficVerdict $verdict, string $group): ?Response
    {
        $isProtectedPost = $request->isMethod('POST') && in_array($group, ['login', 'contact', 'tool-usage', 'smtp-test', 'smtp-send'], true);

        // Protected POST endpoints accept no automated clients at all
        // (admin toggle: bot_block_automated_posts).
        if ($isProtectedPost && SiteSetting::get('bot_block_automated_posts', '1') === '1') {
            return $this->block($request, $verdict, $group, 'policy-post-endpoint');
        }

        // Everything else (pages, search, scheduler, misc API) is readable
        // for bots under a per-IP rate limit.
        $key = ($verdict->isSuspicious() ? 'bot-suspicious|' : 'bot-page|').$request->ip();
        $limit = $verdict->isSuspicious()
            ? $this->intSetting('bot_suspicious_rate_limit', config('bot.suspicious_rate_limit', 30), 5, 120)
            : $this->intSetting('bot_page_rate_limit', config('bot.page_rate_limit', 60), 10, 600);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));

            BotMetrics::recordNamed($verdict->category, (string) $verdict->botName, $group, BotMetrics::ACTION_RATE_LIMITED, 'rate-limit-page');
            BotMetrics::markCounted($request, BotMetrics::ACTION_RATE_LIMITED);

            return response('Too many requests.', Response::HTTP_TOO_MANY_REQUESTS)
                ->withHeaders([
                    'Retry-After' => $retryAfter,
                    'X-RateLimit-Limit' => $limit,
                    'X-RateLimit-Remaining' => 0,
                ]);
        }

        RateLimiter::hit($key, 60);

        BotMetrics::record($verdict, $group, BotMetrics::ACTION_ALLOWED);
        BotMetrics::markCounted($request, BotMetrics::ACTION_ALLOWED);

        return null;
    }

    protected function block(Request $request, TrafficVerdict $verdict, string $group, string $reason): Response
    {
        BotMetrics::recordNamed($verdict->category, (string) $verdict->botName, $group, BotMetrics::ACTION_BLOCKED, $reason);
        BotMetrics::markCounted($request, BotMetrics::ACTION_BLOCKED);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        return response()->view('errors.404', [
            'code' => Response::HTTP_FORBIDDEN,
            'seo' => SeoResolver::normalize(null, 'Something Went Wrong', null),
            'pageType' => 'error',
        ], Response::HTTP_FORBIDDEN);
    }

    protected function endpointGroup(Request $request): string
    {
        $path = trim($request->path(), '/');

        if ($path === 'up') {
            return 'health';
        }

        if ($request->is('admin/login')) {
            return 'login';
        }

        return match (true) {
            $path === 'contact' => 'contact',
            $path === 'api/tool-usage' => 'tool-usage',
            $path === 'api/visitor-locale' => 'visitor-locale',
            $path === 'api/smtp/test-connection' => 'smtp-test',
            $path === 'api/smtp/send-test-email' => 'smtp-send',
            $path === 'tools/search' => 'search',
            str_starts_with($path, 'run-scheduler') => 'scheduler',
            str_starts_with($path, 'api/') => 'api',
            default => 'page',
        };
    }

    protected function enabled(): bool
    {
        if (config('bot.enabled') !== true) {
            return false;
        }

        try {
            return SiteSetting::get('bot_protection_enabled', '1') === '1';
        } catch (\Throwable) {
            // Settings unavailable (e.g. cache backend failure): fail-safe —
            // protection stays on with configured defaults.
            return true;
        }
    }

    protected function intSetting(string $key, int $default, int $min, int $max): int
    {
        try {
            $value = SiteSetting::get($key);
        } catch (\Throwable) {
            return (int) $default;
        }

        if ($value === null || ! ctype_digit((string) $value)) {
            return (int) $default;
        }

        return max($min, min($max, (int) $value));
    }
}
