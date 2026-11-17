<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\Smtp\SmtpConnectionFactoryInterface;
use App\Services\Smtp\SmtpConnectionInterface;
use App\Services\Smtp\SmtpResponse;
use App\Support\CrawlerVerifier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Enforcement and counter behaviour end-to-end through the HTTP kernel.
 * The crawler verifier is faked so tests never touch DNS.
 */
class BotProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
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

    protected function metric(string $category, ?string $action = null, ?string $endpoint = null): int
    {
        $q = DB::table('bot_traffic_metrics')->where('category', $category);

        if ($action !== null) {
            $q->where('action', $action);
        }

        if ($endpoint !== null) {
            $q->where('endpoint_group', $endpoint);
        }

        return (int) $q->sum('requests');
    }

    public function test_verified_crawler_can_read_pages_and_is_counted_allowed(): void
    {
        $this->bindVerifier(CrawlerVerifier::VERIFIED);

        $this->get('/', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->assertStatus(200);

        $this->assertSame(1, $this->metric('verified_search_bot', 'allowed'));
        $this->assertSame(1, $this->metric('verified_search_bot'));
    }

    public function test_verified_crawler_is_blocked_from_protected_post_endpoints(): void
    {
        $this->bindVerifier(CrawlerVerifier::VERIFIED);

        $this->post('/api/tool-usage', [
            'tool' => 'word-counter',
            'success' => 'true',
        ], [
            'User-Agent' => 'Mozilla/5.0 (compatible; bingbot/2.0)',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertStatus(403);

        $this->assertDatabaseCount('tool_usage', 0);
        $this->assertSame(1, $this->metric('verified_search_bot', 'blocked', 'tool-usage'));
    }

    public function test_suspicious_fake_googlebot_is_rate_limited_on_pages(): void
    {
        $this->bindVerifier(CrawlerVerifier::FAILED);

        for ($i = 0; $i < 5; $i++) {
            $this->get('/', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->assertStatus(200);
        }

        // Above the suspicious page limit (default 30/min per IP) → 429.
        for ($i = 0; $i < 30; $i++) {
            $this->get('/', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']);
        }

        $this->assertSame(30, $this->metric('suspicious', 'allowed'));
        $this->assertSame(5, $this->metric('suspicious', 'rate_limited'));
    }

    public function test_suspicious_bot_is_blocked_from_contact_form(): void
    {
        $this->bindVerifier(CrawlerVerifier::FAILED);

        $this->post('/contact', [
            'name' => 'Spam Bot',
            'email' => 'bot@example.com',
            'subject' => 'Spam',
            'message' => 'Automated spam message.',
        ], [
            'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)',
        ])->assertStatus(403);

        $this->assertDatabaseCount('contact_messages', 0);
        $this->assertSame(1, $this->metric('suspicious', 'blocked', 'contact'));
    }

    public function test_normal_visitor_is_unaffected_and_not_counted(): void
    {
        $ua = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36'];

        $this->get('/', $ua)->assertStatus(200);
        $this->post('/api/tool-usage', ['tool' => 'word-counter', 'success' => 'true'], $ua)->assertOk();

        $this->assertDatabaseCount('bot_traffic_metrics', 0);
        $this->assertDatabaseCount('tool_usage', 1);
    }

    public function test_unknown_traffic_is_allowed_and_counted_not_human(): void
    {
        $this->get('/', ['User-Agent' => 'RealBrowser/1.0'])->assertStatus(200);

        $this->assertSame(1, $this->metric('unknown', 'unknown_seen'));

        // Existing analytics behaviour is preserved (unknown page views are
        // still tracked there), but the bot dashboard keeps unknown traffic
        // in its own category — it is never labelled human.
        $this->assertDatabaseCount('page_views', 1);
    }

    public function test_honeypot_submission_is_counted_blocked_but_behaviour_preserved(): void
    {
        $this->post('/contact', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'subject' => 'Hello',
            'message' => 'A legitimate message.',
            'website' => 'http://spam.example',
        ])->assertSessionHasErrors('website');

        $this->assertDatabaseCount('contact_messages', 0);
        $this->assertSame(1, $this->metric('suspicious', 'blocked', 'contact'));
    }

    public function test_rate_limited_human_requests_are_counted(): void
    {
        $ua = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36'];

        // Contact submit is throttled to 5/minute.
        for ($i = 0; $i < 6; $i++) {
            $this->post('/contact', [
                'name' => 'Jane',
                'email' => 'jane@example.com',
                'subject' => 'Hello '.$i,
                'message' => 'Message '.$i,
            ], $ua);
        }

        $this->assertSame(1, $this->metric('human', 'rate_limited', 'contact'));
    }

    public function test_duplicate_tool_usage_events_are_rejected_and_counted(): void
    {
        $ua = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36'];
        $payload = ['tool' => 'word-counter', 'success' => 'true', 'event' => 'run'];

        $this->post('/api/tool-usage', $payload, $ua)->assertOk();
        $this->post('/api/tool-usage', $payload, $ua)->assertOk();

        // Only one real processing event is recorded; the duplicate is
        // rejected by deduplication and counted.
        $this->assertDatabaseCount('tool_usage', 1);
        $this->assertSame(1, $this->metric('human', 'duplicate_rejected', 'tool-usage'));
    }

    public function test_bot_usage_is_excluded_from_human_tool_analytics(): void
    {
        $this->bindVerifier(CrawlerVerifier::VERIFIED);

        // Verified crawler POST is blocked by the guard and never recorded.
        $this->post('/api/tool-usage', [
            'tool' => 'word-counter',
            'success' => 'true',
        ], ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->assertStatus(403);

        $this->assertDatabaseCount('tool_usage', 0);
    }

    public function test_smtp_rate_limits_are_preserved(): void
    {
        // Scripted connection: no real network traffic in tests.
        $connection = new class implements SmtpConnectionInterface
        {
            public array $responses = [[220, ['1.1.1.1 ESMTP ready']], [250, ['1.1.1.1 greets you', 'SIZE 52428800']]];

            protected int $cursor = 0;

            public function connect(): void {}

            public function readResponse(): SmtpResponse
            {
                if (! isset($this->responses[$this->cursor])) {
                    $this->cursor = 0;
                }

                [$code, $lines] = $this->responses[$this->cursor++];

                return new SmtpResponse($code, $lines);
            }

            public function send(string $command): void {}

            public function startTls(): void {}

            public function latencyMs(): int
            {
                return 42;
            }

            public function close(): void {}
        };

        $this->app->instance(
            SmtpConnectionFactoryInterface::class,
            new class($connection) implements SmtpConnectionFactoryInterface
            {
                public function __construct(private readonly object $connection) {}

                public function make(string $host, string $ip, int $port, string $encryption, int $timeoutSeconds = 10): SmtpConnectionInterface
                {
                    return $this->connection;
                }
            },
        );

        $ua = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36'];

        $statuses = [];

        // Existing limit: 10/minute for connection tests.
        foreach (range(1, 12) as $i) {
            $statuses[] = $this->post('/api/smtp/test-connection', [
                'host' => '1.1.1.1',
                'port' => 587,
                'encryption' => 'none',
            ], $ua)->status();
        }

        $this->assertSame(10, collect($statuses)->filter(fn ($s) => $s === 200)->count());
        $this->assertSame(2, collect($statuses)->filter(fn ($s) => $s === 429)->count());
        $this->assertTrue($this->metric('human', 'rate_limited', 'smtp-test') >= 1);
    }

    public function test_middleware_layers_do_not_double_count(): void
    {
        $this->bindVerifier(CrawlerVerifier::VERIFIED);

        // A blocked bot must produce exactly one blocked row, even though
        // the response also passes the 429/403 response watcher.
        $this->post('/api/tool-usage', [
            'tool' => 'word-counter',
            'success' => 'true',
        ], [
            'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertStatus(403);

        $rows = DB::table('bot_traffic_metrics')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]->requests);
    }

    public function test_classification_service_is_used_not_client_input(): void
    {
        $ua = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36'];

        // The client cannot declare itself human or a bot; only server-side
        // classification decides. Posting classification fields changes
        // nothing about counting or enforcement.
        $this->post('/api/tool-usage', [
            'tool' => 'word-counter',
            'success' => 'true',
            'is_bot' => '0',
            'traffic_classification' => 'human',
        ], $ua)->assertOk();

        $this->assertDatabaseCount('bot_traffic_metrics', 0);
    }

    public function test_raw_ip_is_never_stored_in_bot_tables(): void
    {
        $this->bindVerifier(CrawlerVerifier::FAILED);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->post('/api/tool-usage', ['tool' => 'word-counter', 'success' => 'true'], [
                'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)',
                'X-Requested-With' => 'XMLHttpRequest',
            ])->assertStatus(403);

        foreach (DB::table('bot_traffic_metrics')->get() as $row) {
            $this->assertStringNotContainsString('203.0.113.99', json_encode($row));
        }

        foreach (DB::table('bot_event_log')->get() as $row) {
            $this->assertStringNotContainsString('203.0.113.99', json_encode($row));
        }
    }

    public function test_disabling_protection_stops_counting_and_enforcement(): void
    {
        SiteSetting::set('bot_protection_enabled', '0');

        $this->bindVerifier(CrawlerVerifier::VERIFIED);

        $this->post('/api/tool-usage', [
            'tool' => 'word-counter',
            'success' => 'true',
        ], ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->assertOk();

        $this->assertDatabaseCount('bot_traffic_metrics', 0);
        $this->assertDatabaseCount('tool_usage', 0); // excluded from human analytics by classifier
    }
}
