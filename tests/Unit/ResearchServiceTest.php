<?php

namespace Tests\Unit;

use App\Models\AiGeneration;
use App\Models\AiSetting;
use App\Models\AutomationSetting;
use App\Models\BlogPost;
use App\Models\BlogResearchSource;
use App\Models\ResearchRun;
use App\Models\ResearchSetting;
use App\Services\Ai\BlogGeneratorService;
use App\Services\Research\ResearchResult;
use App\Services\Research\ResearchService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResearchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function configureResearch(array $overrides = []): ResearchSetting
    {
        $settings = ResearchSetting::current();
        $settings->update($overrides + [
            'enabled' => true,
            'sources_per_source' => 3,
            'provider' => 'none',
        ]);

        return $settings;
    }

    public function test_wikipedia_results_are_normalized_with_real_urls(): void
    {
        $this->configureResearch();
        Http::fake([
            'https://en.wikipedia.org/w/rest.php/v1/search/page*' => Http::response([
                'pages' => [
                    ['id' => 1, 'key' => 'Letter_case', 'title' => 'Letter case', 'excerpt' => '<span class="searchmatch">Letter</span> case is the distinction between letters.', 'description' => 'Typographical concept'],
                ],
            ]),
        ]);

        $results = app(ResearchService::class)->fromWikipedia('letter case', 3);

        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertEquals('https://en.wikipedia.org/wiki/Letter_case', $result->url);
        $this->assertEquals('Letter case', $result->title);
        $this->assertEquals('wikipedia', $result->sourceType);
        $this->assertStringNotContainsString('<span', $result->snippet);
        $this->assertStringContainsString('Letter case is the distinction', $result->snippet);
    }

    public function test_hackernews_falls_back_to_item_url_when_missing(): void
    {
        $this->configureResearch();
        Http::fake([
            'https://hn.algolia.com/api/v1/search*' => Http::response([
                'hits' => [
                    ['objectID' => '424242', 'title' => 'Show HN: Text tools', 'url' => null, 'points' => 120, 'story_text' => 'A collection of text utilities.'],
                ],
            ]),
        ]);

        $results = app(ResearchService::class)->fromHackerNews('text tools', 3);

        $this->assertCount(1, $results);
        $this->assertEquals('https://news.ycombinator.com/item?id=424242', $results[0]->url);
        $this->assertEquals('hackernews', $results[0]->sourceType);
    }

    public function test_rss_feeds_are_parsed(): void
    {
        $this->configureResearch([
            'rss_feeds' => ['https://feeds.example.com/blog.xml'],
        ]);

        $feed = <<<'XML'
<rss version="2.0"><channel>
<item><title>Feed Story One</title><link>https://feeds.example.com/story-1</link><description><![CDATA[First description]]></description><pubDate>Sun, 15 Nov 2026 10:00:00 +0000</pubDate></item>
<item><title>Feed Story Two</title><link>https://feeds.example.com/story-2</link><description>Second description</description></item>
</channel></rss>
XML;

        Http::fake(['https://feeds.example.com/blog.xml' => Http::response($feed, 200, ['Content-Type' => 'application/xml'])]);

        $results = app(ResearchService::class)->fromRssFeeds(5);

        $this->assertCount(2, $results);
        $this->assertEquals('https://feeds.example.com/story-1', $results[0]->url);
        $this->assertEquals('rss', $results[0]->sourceType);
    }

    public function test_web_search_tavily_results_are_normalized(): void
    {
        $this->configureResearch(['provider' => 'tavily', 'api_key' => 'tvly-test']);
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'results' => [
                    ['title' => 'Web Result', 'url' => 'https://web.example.com/article', 'content' => 'Real web content.', 'score' => 0.93],
                ],
            ]),
        ]);

        $results = app(ResearchService::class)->fromWebSearch('case converter', 3);

        $this->assertCount(1, $results);
        $this->assertEquals('https://web.example.com/article', $results[0]->url);
        $this->assertEquals('web', $results[0]->sourceType);
    }

    public function test_web_search_without_provider_returns_nothing(): void
    {
        $this->configureResearch(['provider' => 'none']);

        $results = app(ResearchService::class)->fromWebSearch('anything', 3);

        $this->assertCount(0, $results);
    }

    public function test_web_search_without_api_key_records_error(): void
    {
        $this->configureResearch(['provider' => 'serpapi', 'api_key' => null]);

        $this->expectException(\RuntimeException::class);
        app(ResearchService::class)->fromWebSearch('anything', 3);
    }

    public function test_sources_are_deduplicated(): void
    {
        $service = app(ResearchService::class);

        $results = new Collection([
            new ResearchResult('https://example.com/post/', 'One', 's', 'web', now()->toIso8601String()),
            new ResearchResult('https://example.com/post', 'One duplicate', 's', 'web', now()->toIso8601String()),
            new ResearchResult('https://other.example.com/post', 'Different', 's', 'web', now()->toIso8601String()),
        ]);

        $method = new \ReflectionMethod($service, 'deduplicate');
        $deduped = $method->invoke($service, $results);

        $this->assertCount(2, $deduped);
    }

    public function test_trusted_domain_whitelist_filters_web_but_keeps_rss(): void
    {
        $this->configureResearch(['trusted_domains' => ['example.com']]);
        $service = app(ResearchService::class);

        $results = new Collection([
            new ResearchResult('https://example.com/a', 'Allowed', 's', 'web', now()->toIso8601String()),
            new ResearchResult('https://untrusted.org/b', 'Blocked', 's', 'web', now()->toIso8601String()),
            new ResearchResult('https://untrusted.org/feed-item', 'Feed kept', 's', 'rss', now()->toIso8601String()),
        ]);

        $filtered = $service->filterByTrustedDomains($results);

        $this->assertCount(2, $filtered);
        $this->assertTrue($filtered->contains(fn (ResearchResult $r) => $r->title === 'Feed kept'));
        $this->assertFalse($filtered->contains(fn (ResearchResult $r) => $r->title === 'Blocked'));
    }

    public function test_unavailable_source_degrades_to_partial_run(): void
    {
        $this->configureResearch();
        Http::fake([
            'https://en.wikipedia.org/*' => Http::response('down', 500),
            'https://hn.algolia.com/*' => Http::response(['hits' => [['objectID' => '9', 'title' => 'HN Story', 'url' => 'https://example.com/x']]]),
        ]);

        $outcome = app(ResearchService::class)->researchForTopic('text tools');

        $this->assertEquals('partial', $outcome['run']->status);
        $this->assertCount(1, $outcome['results']);
        $this->assertCount(1, $outcome['run']->errors);
        $this->assertStringContainsString('Wikipedia', $outcome['run']->errors[0]);
    }

    public function test_all_sources_failing_yields_failed_run_without_invented_sources(): void
    {
        $this->configureResearch();
        Http::fake([
            'https://en.wikipedia.org/*' => Http::response('down', 500),
            'https://hn.algolia.com/*' => Http::response('down', 500),
            '*' => Http::response('down', 500),
        ]);

        $outcome = app(ResearchService::class)->researchForTopic('text tools');

        $this->assertEquals('failed', $outcome['run']->status);
        $this->assertCount(0, $outcome['results']);
        $this->assertGreaterThanOrEqual(2, count($outcome['run']->errors));
    }

    public function test_disabled_research_fails_run(): void
    {
        $this->configureResearch(['enabled' => false]);

        $outcome = app(ResearchService::class)->researchForTopic('anything');

        $this->assertEquals('failed', $outcome['run']->status);
        $this->assertCount(0, $outcome['results']);
    }

    public function test_generation_with_research_stores_and_links_provenance(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->configureResearch();

        AiSetting::create([
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'model' => 'gpt-test',
            'is_active' => true,
        ]);

        AutomationSetting::query()->update([
            'is_enabled' => true,
            'topics' => ['How to convert text case online'],
        ]);

        $research = new Collection([
            new ResearchResult('https://en.wikipedia.org/wiki/Letter_case', 'Letter case', 'Letter case is typographical.', 'wikipedia', now()->toIso8601String(), 80),
            new ResearchResult('https://example.com/case-tools', 'Case tools roundup', 'A roundup of tools.', 'web', now()->toIso8601String(), 60),
        ]);

        $run = ResearchRun::create(['topic' => 'How to convert text case online', 'status' => 'completed', 'sources_found' => 2]);

        $filler = trim(str_repeat('content ', 1000));
        $payload = json_encode([
            'title' => 'How to Convert Text Case Online',
            'slug' => 'researched-article',
            'excerpt' => 'A researched guide.',
            'content_html' => '<h2>Guide</h2><p>Converting text case matters. '.$filler.'</p>',
            'focus_keyword' => 'convert text case',
            'seo_title' => 'Convert Text Case Online',
            'meta_description' => 'Learn how to convert text case online.',
            'blog_category' => 'Productivity',
        ]);

        Http::fake([
            'https://api.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $payload]]],
                'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 900],
            ]),
        ]);

        $result = app(BlogGeneratorService::class)->generateForTopic('How to convert text case online', context: [
            'research' => $research,
            'research_run_id' => $run->id,
        ]);

        $this->assertTrue($result['success'], $result['message']);

        $post = BlogPost::query()->where('slug', 'researched-article')->firstOrFail();

        $sources = BlogResearchSource::query()->where('blog_post_id', $post->id)->get();
        $this->assertCount(2, $sources);
        $this->assertTrue($sources->contains(fn ($s) => $s->url === 'https://en.wikipedia.org/wiki/Letter_case'));
        $this->assertNotNull($sources->first()->fetched_at);
        $this->assertNotNull($sources->first()->ai_generation_id);
        $this->assertNotNull($sources->first()->research_run_id);

        // The prompt must have contained the research extracts.
        Http::assertSent(function ($request) {
            $messages = $request['messages'] ?? [];

            return str_contains((string) ($messages[1]['content'] ?? ''), 'RESEARCH EXTRACTS')
                && str_contains((string) ($messages[1]['content'] ?? ''), 'https://en.wikipedia.org/wiki/Letter_case');
        });
    }

    public function test_generation_without_research_still_works(): void
    {
        $this->seed(DatabaseSeeder::class);

        AiSetting::create([
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'model' => 'gpt-test',
            'is_active' => true,
        ]);

        $filler = trim(str_repeat('content ', 1000));
        $payload = json_encode([
            'title' => 'Plain Article',
            'slug' => 'plain-article',
            'content_html' => '<h2>Plain</h2><p>Plain text. '.$filler.'</p>',
            'focus_keyword' => 'plain text',
            'seo_title' => 'Plain Article',
            'meta_description' => 'A plain article.',
            'blog_category' => 'Productivity',
        ]);

        Http::fake([
            'https://api.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $payload]]],
            ]),
        ]);

        $result = app(BlogGeneratorService::class)->generateForTopic('Plain article topic');

        $this->assertTrue($result['success'], $result['message']);
        $this->assertEquals(0, BlogResearchSource::query()->count());
        $this->assertEquals(1, AiGeneration::query()->where('status', 'completed')->count());
    }

    public function test_research_run_persists_topic_and_counts(): void
    {
        $this->configureResearch();
        Http::fake([
            'https://hn.algolia.com/*' => Http::response(['hits' => [['objectID' => '5', 'title' => 'Story', 'url' => 'https://example.com/a']]]),
            '*' => Http::response(''),
        ]);

        $generation = AiGeneration::create(['topic' => 't', 'status' => 'pending']);
        $outcome = app(ResearchService::class)->researchForTopic('story topic', $generation);

        $run = $outcome['run'];
        $run->refresh();

        $this->assertEquals('completed', $run->status);
        $this->assertEquals(1, $run->sources_found);
        $this->assertEquals($generation->id, $run->ai_generation_id);
    }
}
