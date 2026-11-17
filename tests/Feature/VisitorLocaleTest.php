<?php

namespace Tests\Feature;

use App\Models\Visitor;
use App\Support\Analytics;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Visitor country hint endpoint (POST /api/visitor-locale).
 *
 * The hint stores the country ONCE per visitor and only when no value
 * exists yet; bots are ignored. Historical context: the old JS hint derived
 * the country from the browser LANGUAGE ("en-US" -> US), which mislabelled
 * almost everyone; the hint is now derived from the browser TIMEZONE
 * (Asia/Dhaka -> BD) — this endpoint itself is source-agnostic.
 */
class VisitorLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function postHint(array $payload = [], array $headers = [])
    {
        return $this->withHeaders($headers)
            ->postJson('/api/visitor-locale', $payload + ['_token' => csrf_token()]);
    }

    /**
     * The endpoint annotates visitors created by the page-view pipeline;
     * simulate that a page view was recorded first.
     */
    protected function makeVisitor(string $ua = 'RealBrowser/1.0'): Visitor
    {
        $request = Request::create('/api/visitor-locale', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => $ua,
        ]);

        return Visitor::query()->firstOrCreate(
            ['visitor_hash' => Analytics::fingerprint($request)],
            ['first_seen_at' => now()]
        );
    }

    public function test_stores_country_for_a_visitor_once(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->makeVisitor();
        $this->postHint(['country' => 'BD'], ['User-Agent' => 'RealBrowser/1.0'])
            ->assertNoContent();

        $visitor = Visitor::query()->firstOrFail();
        $this->assertSame('BD', $visitor->locale_country);

        // A second, different value must never overwrite the first hint.
        $this->postHint(['country' => 'US'], ['User-Agent' => 'RealBrowser/1.0'])
            ->assertNoContent();

        $this->assertSame('BD', $visitor->refresh()->locale_country);
    }

    public function test_missing_country_is_ignored(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->makeVisitor();

        $this->postHint([], ['User-Agent' => 'RealBrowser/1.0'])
            ->assertNoContent();

        $visitor = Visitor::query()->firstOrFail();
        $this->assertNull($visitor->locale_country);
    }

    public function test_invalid_country_is_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['USA', '12', 'D!', 'BDX'] as $bad) {
            $this->postHint(['country' => $bad], ['User-Agent' => 'RealBrowser/1.0'])
                ->assertStatus(422);
        }

        $this->assertSame(0, Visitor::query()->count(), 'Nothing meaningful was stored.');
    }

    public function test_bots_are_not_recorded(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->postHint(['country' => 'BD'], ['User-Agent' => 'Mozilla/5.0 (compatible; GPTBot/1.0)'])
            ->assertNoContent();

        $this->assertSame(0, Visitor::query()->count(), 'Bot hits must never create visitor rows.');
    }

    public function test_fingerprint_matches_the_page_view_pipeline(): void
    {
        $this->seed(DatabaseSeeder::class);

        $visitor = $this->makeVisitor();

        $this->postHint(['country' => 'BD'], ['User-Agent' => 'RealBrowser/1.0'])
            ->assertNoContent();

        $this->assertSame('BD', $visitor->refresh()->locale_country);
        $this->assertDatabaseHas('visitors', ['visitor_hash' => $visitor->visitor_hash, 'locale_country' => 'BD']);
    }
}
