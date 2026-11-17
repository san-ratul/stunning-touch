<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Centralized, testable traffic classification.
 *
 * Every relevant request is placed into exactly one category:
 *
 * - Human                — recognizable real-browser user agent.
 * - Verified Search Bot  — claimed search crawler whose client IP passed
 *                          forward-confirmed reverse DNS verification.
 * - Known Bot            — a recognized automated client (library, scraper,
 *                          monitoring or preview bot). Never trusted as a
 *                          search engine merely from its user agent.
 * - Suspicious Automation — crawler claims that fail verification,
 *                          honeypot failures and similar server-observable
 *                          signals.
 * - Unknown              — anything else (scripted clients with empty or
 *                          unrecognizable user agents). Unknown is never
 *                          silently relabelled as human.
 *
 * User-Agent alone is not sufficient proof of identity: a fake Googlebot
 * User-Agent must not be trusted, so every claimed search crawler is
 * verified server-side (results are cached to avoid repeated DNS lookups).
 */
class TrafficClassifier
{
    /** Known crawler families that are verified by reverse DNS. */
    public const SEARCH_CRAWLERS = [
        'googlebot' => ['googlebot.com', 'google.com', 'googleusercontent.com', 'yokota.jp.or'],
        'bingbot' => ['search.msn.com', 'bing.com'],
        'duckduckbot' => ['duckduckgo.com'],
        'yandexbot' => ['yandex.ru', 'yandex.net', 'yandex.com'],
        'baiduspider' => ['baidu.com', 'baidu.jp'],
        'applebot' => ['apple.com'],
        'sogou' => ['sogou.com', 'sogou-inc.com'],
    ];

    /** Recognized automation: libraries, scrapers, monitors, preview bots. */
    protected const KNOWN_AUTOMATION = [
        'curl' => '/^curl\//i',
        'wget' => '/^wget\//i',
        'python-requests' => '/^python-requests\//i',
        'python-urllib' => '/^python-urllib\//i',
        'http-client' => '/^(go-http-client|java|apache-httpclient|libwww-perl|okhttp|php\/|node-fetch|undici|axios)/i',
        'scrapy' => '/^scrapy/i',
        'ahrefs' => '/ahrefsbot/i',
        'semrush' => '/semrushbot/i',
        'mj12' => '/mj12bot/i',
        'dotbot' => '/^dotbot/i',
        'petalbot' => '/petalbot/i',
        'screaming-frog' => '/screaming frog/i',
        'headless-chrome' => '/headlesschrome/i',
        'lighthouse' => '/chrome-lighthouse/i',
        'pagespeed' => '/pagespeed/i',
        'uptime-monitor' => '/(uptimerobot|pingdom|statuscake|uptime-kuma|site24x7|freshping|sentry uptime)/i',
        'social-preview' => '/(facebookexternalhit|twitterbot|linkedinbot|slackbot|discordbot|whatsapp|telegrambot|pinterestbot|skypeuripreview|embedly)/i',
        'ai-crawler' => '/(gptbot|ccbot|anthropic-ai|claudebot|bytespider|amazonbot|google-extended|perplexitybot|omgili)/i',
        'seo-tool' => '/(majestic|blexbot|dataforseo|serpstatbot|proximic|seznambot|megaindex|linkdex|sistrix)/i',
        'generic-crawler' => '/(bot|crawl|spider|slurp|archiver|fetcher|monitor)/i',
    ];

    /** Real-browser user agent signature. */
    protected const BROWSER_PATTERN = '/^mozilla\/5\.0.*(chrome\/|crios\/|firefox\/|fxios\/|safari\/|edg\/|edga\/|edgios\/|trident\/|gecko\/|opr\/|opera)/i';

    /** Claimed search crawler user agents, mapped to the verifying family. */
    protected const CRAWLER_CLAIM_PATTERN = '/(googlebot|bingbot|duckduckbot|duckduckgo|yandexbot|yandex\.com\/bots|baiduspider|applebot|sogou (spider|head pagerank))/i';

    public function __construct(protected readonly CrawlerVerifier $verifier) {}

    public static function classify(Request $request): TrafficVerdict
    {
        return app(self::class)->verdict($request);
    }

    public function verdict(Request $request): TrafficVerdict
    {
        $ua = trim((string) $request->userAgent());

        if ($ua === '') {
            return new TrafficVerdict(TrafficVerdict::UNKNOWN, 'empty-user-agent');
        }

        // A claimed search crawler must be verified server-side; the user
        // agent is never accepted as proof on its own.
        if (preg_match(self::CRAWLER_CLAIM_PATTERN, $ua)) {
            return $this->verifyClaim($request, $ua);
        }

        if (preg_match(self::BROWSER_PATTERN, $ua) && ! preg_match('/(headlesschrome|chrome-lighthouse|pagespeed|bot|crawl|spider|slurp)/i', $ua)) {
            return new TrafficVerdict(TrafficVerdict::HUMAN, 'browser-user-agent');
        }

        foreach (self::KNOWN_AUTOMATION as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                return new TrafficVerdict(TrafficVerdict::KNOWN_BOT, 'known-automation-user-agent', $name);
            }
        }

        return new TrafficVerdict(TrafficVerdict::UNKNOWN, 'unrecognized-user-agent');
    }

    protected function verifyClaim(Request $request, string $ua): TrafficVerdict
    {
        $family = 'other-crawler';

        foreach (self::SEARCH_CRAWLERS as $name => $domains) {
            if (stripos($ua, $name) !== false) {
                $family = $name;
                break;
            }
        }

        $ip = (string) $request->ip();

        $result = $this->verifier->verify($ip, $ua, $family);

        return match ($result) {
            CrawlerVerifier::VERIFIED => new TrafficVerdict(TrafficVerdict::VERIFIED_SEARCH_BOT, 'crawler-verified-reverse-dns', $family),
            CrawlerVerifier::FAILED => new TrafficVerdict(TrafficVerdict::SUSPICIOUS, 'crawler-claim-unverified', $family),
            default => new TrafficVerdict(TrafficVerdict::KNOWN_BOT, 'crawler-verification-unavailable', $family),
        };
    }

    /**
     * Server-side classification that also records a honeypot failure.
     * Used by enforcement sites (e.g. the contact form honeypot).
     */
    public static function honeypotVerdict(): TrafficVerdict
    {
        return new TrafficVerdict(TrafficVerdict::SUSPICIOUS, 'honeypot', 'form-spammer');
    }
}
