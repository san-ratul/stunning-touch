<?php

namespace Tests\Unit;

use App\Support\CrawlerVerifier;
use App\Support\TrafficClassifier;
use App\Support\TrafficVerdict;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Classification matrix. Crawler claims are verified through the bound
 * CrawlerVerifier contract — the unit tests bind a fake so no DNS happens.
 */
class TrafficClassifierTest extends TestCase
{
    protected function classify(string $url, string $userAgent, array $headers = [], array $server = []): TrafficVerdict
    {
        $server = array_merge(['HTTP_USER_AGENT' => $userAgent], $server);

        $request = Request::create($url, 'GET', [], [], [], $server);

        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }

        return TrafficClassifier::classify($request);
    }

    protected function bindVerifier(string $result): void
    {
        $this->app->bind(CrawlerVerifier::class, fn () => new class($result) implements CrawlerVerifier
        {
            public function __construct(private readonly string $result) {}

            public function verify(string $ip, string $userAgent, string $family): string
            {
                return $this->result;
            }
        });
    }

    public function test_normal_human_visitor_is_human(): void
    {
        $verdict = $this->classify('/', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36');

        $this->assertTrue($verdict->category === TrafficVerdict::HUMAN);
        $this->assertFalse($verdict->isBot());
    }

    public function test_verified_googlebot_is_a_verified_search_bot(): void
    {
        $this->bindVerifier(CrawlerVerifier::VERIFIED);

        $verdict = $this->classify('/', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');

        $this->assertSame(TrafficVerdict::VERIFIED_SEARCH_BOT, $verdict->category);
        $this->assertSame('googlebot', $verdict->botName);
    }

    public function test_verified_bingbot_is_a_verified_search_bot(): void
    {
        $this->bindVerifier(CrawlerVerifier::VERIFIED);

        $verdict = $this->classify('/', 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)');

        $this->assertSame(TrafficVerdict::VERIFIED_SEARCH_BOT, $verdict->category);
        $this->assertSame('bingbot', $verdict->botName);
    }

    public function test_fake_googlebot_user_agent_is_suspicious(): void
    {
        // Verification performed server-side and definitively failed.
        $this->bindVerifier(CrawlerVerifier::FAILED);

        $verdict = $this->classify('/', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');

        $this->assertSame(TrafficVerdict::SUSPICIOUS, $verdict->category);
        $this->assertSame('crawler-claim-unverified', $verdict->reason);
    }

    public function test_crawler_claim_without_verification_is_known_bot_fail_safe(): void
    {
        // DNS/verification unavailable: never human, never falsely verified.
        $this->bindVerifier(CrawlerVerifier::UNAVAILABLE);

        $verdict = $this->classify('/', 'Mozilla/5.0 (compatible; Googlebot/2.1)');

        $this->assertSame(TrafficVerdict::KNOWN_BOT, $verdict->category);
        $this->assertTrue($verdict->isBot());
    }

    public function test_known_bots_are_recognized(): void
    {
        foreach ([
            'curl/8.4.0' => 'curl',
            'GPTBot/1.0 (+https://openai.com/gptbot)' => 'ai-crawler',
            'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)' => 'ahrefs',
            'facebookexternalhit/1.1' => 'social-preview',
            'Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/120.0.0.0' => 'headless-chrome',
        ] as $ua => $name) {
            $verdict = $this->classify('/', $ua);

            $this->assertSame(TrafficVerdict::KNOWN_BOT, $verdict->category, "UA {$ua}");
            $this->assertSame($name, $verdict->botName, "UA {$ua}");
        }
    }

    public function test_unknown_traffic_is_not_human(): void
    {
        $this->assertSame(TrafficVerdict::UNKNOWN, $this->classify('/', '')->category);
        $this->assertSame(TrafficVerdict::UNKNOWN, $this->classify('/', 'RealBrowser/1.0')->category);
        $this->assertFalse($this->classify('/', 'RealBrowser/1.0')->isBot());
    }

    public function test_client_submitted_classification_is_ignored(): void
    {
        // The server never trusts is_bot / is_human / traffic_classification.
        $verdict = $this->classify('/', 'RealBrowser/1.0', headers: [], server: [
            'HTTP_X_IS_BOT' => '1',
        ]);

        $request = Request::create('/api/tool-usage', 'POST', ['is_bot' => '1', 'is_human' => '0', 'bot_type' => 'googlebot', 'traffic_classification' => 'verified_search_bot'], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36',
        ]);

        $this->assertSame(TrafficVerdict::HUMAN, TrafficClassifier::classify($request)->category);
        $this->assertSame(TrafficVerdict::UNKNOWN, $verdict->category);
    }

    public function test_trusted_proxy_forwarded_ip_is_not_trusted_blindly(): void
    {
        // Without trusted-proxy configuration, spoofed X-Forwarded-For must
        // not change the client IP used for verification.
        $this->bindVerifier(CrawlerVerifier::FAILED);

        $verdict = $this->classify('/', 'Mozilla/5.0 (compatible; Googlebot/2.1)', headers: [
            'X-Forwarded-For' => '66.249.66.1',
        ], server: ['REMOTE_ADDR' => '203.0.113.10']);

        // A fake crawler claim stays suspicious regardless of spoofed XFF.
        $this->assertSame(TrafficVerdict::SUSPICIOUS, $verdict->category);
        $this->assertSame('203.0.113.10', Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '66.249.66.1',
        ])->ip());
    }

    public function test_browser_ua_containing_bot_words_is_not_human(): void
    {
        $verdict = $this->classify('/', 'Mozilla/5.0 (compatible; SomeCrawler/1.0; Chrome/999)');

        $this->assertTrue($verdict->isBot());
    }
}
