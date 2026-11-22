<?php

namespace Tests\Unit;

use App\Models\AutomationSetting;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\PipelineRun;
use App\Models\ResearchSetting;
use App\Models\Tool;
use App\Services\Ai\DuplicateDetectorService;
use App\Services\Ai\KeywordService;
use App\Services\Ai\TopicDiscoveryService;
use App\Services\Research\ResearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TopicAndDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    protected function makePost(array $overrides = []): BlogPost
    {
        return BlogPost::create($overrides + [
            'title' => 'Base Article',
            'slug' => 'base-article',
            'content' => '<h2>Heading</h2><p>Body text.</p>',
            'status' => 'published',
            'published_at' => now(),
            'source' => 'manual',
        ]);
    }

    // ---- DuplicateDetectorService ----

    public function test_identical_titles_are_duplicates(): void
    {
        $this->makePost(['title' => 'How to Convert Text Case Online', 'slug' => 'how-to-convert-text-case-online']);

        $result = app(DuplicateDetectorService::class)->analyze('How to Convert Text Case Online');

        $this->assertTrue($result['duplicate']);
        $this->assertEquals(100.0, $result['best_match']['title_similarity']);
        $this->assertEquals(1, $result['evaluated']);
    }

    public function test_slug_collision_is_duplicate(): void
    {
        $this->makePost(['slug' => 'case-converter-guide']);

        $result = app(DuplicateDetectorService::class)->analyze('An Entirely Different Title', slug: 'case-converter-guide');

        $this->assertTrue($result['duplicate']);
        $this->assertTrue($result['best_match']['slug_collision']);
    }

    public function test_distinct_titles_are_not_duplicates(): void
    {
        $this->makePost(['title' => 'How to merge PDF files without software', 'slug' => 'merge-pdf-files', 'focus_keyword' => 'merge pdf']);

        $result = app(DuplicateDetectorService::class)->analyze('Why image compression speeds up websites', focusKeyword: 'image compression');

        $this->assertFalse($result['duplicate'], json_encode($result['best_match']));
    }

    public function test_near_duplicate_shingles_are_detected(): void
    {
        $this->makePost(['title' => 'How to compress images online without losing quality', 'slug' => 'compress-images-online']);

        $result = app(DuplicateDetectorService::class)->analyze('How to compress images online without losing quality fast');

        $this->assertTrue($result['duplicate'], json_encode($result['best_match']));
        $this->assertNotNull($result['best_match']['shingle_overlap']);
        $this->assertGreaterThanOrEqual(85, $result['best_match']['shingle_overlap']);
    }

    public function test_focus_keyword_overlap_is_reported(): void
    {
        $this->makePost(['title' => 'Completely Different Topic', 'slug' => 'different-topic', 'focus_keyword' => 'image compressor']);

        $result = app(DuplicateDetectorService::class)->analyze('Another Unrelated Headline Here', focusKeyword: 'Image Compressor');

        $this->assertTrue($result['duplicate']);
        $this->assertTrue($result['best_match']['focus_keyword_overlap']);
    }

    public function test_threshold_is_respected(): void
    {
        $this->makePost(['title' => 'How to compress images online for free', 'slug' => 'compress-free']);

        // ~70% similar title: duplicate under a permissive threshold only.
        $lenient = app(DuplicateDetectorService::class)->analyze('How to compress images online quickly today', threshold: 60);
        $strict = app(DuplicateDetectorService::class)->analyze('How to compress images online quickly today', threshold: 90);

        $this->assertTrue($lenient['duplicate']);
        $this->assertFalse($strict['duplicate']);
    }

    public function test_secondary_keyword_overlap_is_detected(): void
    {
        $this->makePost([
            'title' => 'Resize Images Properly',
            'slug' => 'resize-properly',
            'secondary_keywords' => 'resize jpg, resize png, aspect ratio',
        ]);

        $result = app(DuplicateDetectorService::class)->analyze(
            'An Entirely New Subject Line',
            secondaryKeywords: 'resize png, aspect ratio, resize jpg'
        );

        $this->assertTrue($result['duplicate']);
        $this->assertEquals(100.0, $result['best_match']['secondary_keyword_overlap']);
    }

    // ---- KeywordService ----

    public function test_intent_detection_matches_patterns(): void
    {
        $service = app(KeywordService::class);

        $this->assertEquals('how-to', $service->detectIntent('How to convert text case'));
        $this->assertEquals('comparison', $service->detectIntent('WebP versus JPEG for the web'));
        $this->assertEquals('informational', $service->detectIntent('Understanding color profiles'));
    }

    public function test_keywords_derived_from_research_without_fabricated_volume(): void
    {
        $research = new Collection([
            new ResearchResult('https://example.com/a', 'Compression algorithms explained simply', 'Lossy and lossless compression', 'web', now()->toIso8601String()),
            new ResearchResult('https://example.com/b', 'Image compression basics', 'Compression reduces file size', 'web', now()->toIso8601String()),
        ]);

        $result = app(KeywordService::class)->suggest('How to compress images online', $research);

        $this->assertEquals('how-to', $result['intent']);
        $this->assertFalse($result['volume_data_available'], 'Volume data must never be fabricated.');
        $this->assertStringContainsString('not estimated or fabricated', $result['note']);
        $this->assertEquals('compress images', $result['focus']);
        $this->assertNotEmpty($result['secondary']);
        $this->assertTrue(collect($result['secondary'])->contains(fn ($k) => str_contains($k, 'compression')));
    }

    // ---- TopicDiscoveryService ----

    protected function discovery(array $automationOverrides = []): TopicDiscoveryService
    {
        Http::fake(['*' => Http::response('')]);

        $automation = AutomationSetting::current();
        $automation->update($automationOverrides);

        return app(TopicDiscoveryService::class);
    }

    public function test_manual_topics_are_candidates(): void
    {
        $candidates = $this->discovery(['topics' => ['How to watermark photos in bulk']])->discover();

        $this->assertTrue($candidates->contains(fn ($c) => $c['topic'] === 'How to watermark photos in bulk' && $c['source'] === 'manual'));
    }

    public function test_rss_derived_topics_are_included(): void
    {
        $feed = <<<'XML'
<rss version="2.0"><channel>
<item><title>RSS Discovered Topic About Image Optimization</title><link>https://feeds.example.com/a</link><description>x</description></item>
</channel></rss>
XML;

        Http::fake([
            'https://feeds.example.com/*' => Http::response($feed),
            '*' => Http::response(''),
        ]);

        ResearchSetting::current()->update([
            'rss_feeds' => ['https://feeds.example.com/blog.xml'],
        ]);

        $candidates = $this->discovery()->discover();

        $this->assertTrue($candidates->contains(fn ($c) => $c['source'] === 'rss' && str_contains($c['topic'], 'RSS Discovered Topic')));
    }

    public function test_candidates_matching_existing_posts_are_rejected(): void
    {
        $this->makePost(['title' => 'How to Convert Text Case Online', 'slug' => 'convert-text-case-online']);

        $candidates = $this->discovery(['topics' => [
            'How to Convert Text Case Online',
            'Bulk watermark photos with a free browser tool',
        ]])->discover();

        $this->assertFalse($candidates->contains(fn ($c) => $c['topic'] === 'How to Convert Text Case Online'));
        $this->assertTrue($candidates->contains(fn ($c) => str_contains($c['topic'], 'Bulk watermark')));
    }

    public function test_find_eligible_topic_returns_first_passing_candidate(): void
    {
        $this->makePost(['title' => 'How to Convert Text Case Online', 'slug' => 'convert-text-case-online', 'focus_keyword' => 'convert text case']);

        $eligible = $this->discovery(['topics' => [
            'How to Convert Text Case Online',
            'Watermark photos in bulk without uploading them',
        ]])->findEligibleTopic();

        $this->assertNotNull($eligible);
        $this->assertStringContainsString('Watermark', $eligible['topic']);
        $this->assertEquals('manual', $eligible['source']);
    }

    public function test_find_eligible_topic_returns_null_when_exhausted(): void
    {
        $this->makePost(['title' => 'How to Convert Text Case Online', 'slug' => 'convert-text-case-online', 'focus_keyword' => 'convert text case']);
        $this->makePost(['title' => 'Merge PDF files instantly', 'slug' => 'merge-pdf-instantly', 'focus_keyword' => 'merge pdf']);

        $eligible = $this->discovery(['topics' => [
            'How to Convert Text Case Online',
            'Merge PDF files instantly',
        ]])->findEligibleTopic(threshold: 50);

        $this->assertNull($eligible, 'Exhausted topic lists must not be silently reused.');
    }

    public function test_topics_covered_by_completed_runs_are_not_picked_again(): void
    {
        PipelineRun::create([
            'slot_date' => '2026-11-22',
            'slot' => '06:00',
            'status' => PipelineRun::STATUS_COMPLETED,
            'step' => 'finalized',
            'completed_steps' => ['finalized'],
            'topic' => 'Latest AI tools and technology updates',
            'attempts' => 1,
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(1),
        ]);

        $eligible = $this->discovery(['topics' => [
            'Latest AI tools and technology updates',
            'How to merge PDF files online without installing software',
        ]])->findEligibleTopic();

        $this->assertNotNull($eligible, 'An unused topic must still be found.');
        $this->assertStringContainsString('merge PDF', $eligible['topic'], 'A topic that already produced a completed run must be skipped.');
    }

    public function test_ai_post_titles_block_their_source_topic(): void
    {
        // AI articles get their own generated titles, so the configured
        // topic can only be blocked by remembering the title it produced.
        $this->makePost([
            'title' => 'How to merge PDF files online',
            'slug' => 'merge-pdf-online',
            'source' => 'ai',
        ]);

        $eligible = $this->discovery(['topics' => [
            'How to merge PDF files online',
            'How to split PDF files easily',
        ]])->findEligibleTopic();

        $this->assertNotNull($eligible);
        $this->assertStringContainsString('split PDF', $eligible['topic']);
    }

    public function test_failed_runs_do_not_burn_topics(): void
    {
        PipelineRun::create([
            'slot_date' => '2026-11-22',
            'slot' => '06:00',
            'status' => PipelineRun::STATUS_FAILED,
            'step' => 'research',
            'completed_steps' => [],
            'topic' => 'How to merge PDF files online',
            'attempts' => 1,
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(1),
            'error_message' => 'transient research failure',
        ]);

        $eligible = $this->discovery(['topics' => ['How to merge PDF files online']])->findEligibleTopic();

        $this->assertNotNull($eligible, 'A failed attempt must not permanently consume the topic.');
    }

    public function test_content_gaps_suggest_topics_for_uncovered_tools(): void
    {
        $category = Category::create([
            'name' => 'Utility Tools',
            'slug' => 'utility-tools',
            'icon' => 'tools',
            'short_description' => 'Utilities.',
            'display_order' => 99,
            'is_active' => true,
        ]);

        Tool::create([
            'category_id' => $category->id,
            'name' => 'Metadata Viewer',
            'slug' => 'metadata-viewer',
            'short_description' => 'Inspect image metadata.',
            'component_key' => 'image-metadata',
            'is_active' => true,
        ]);

        $candidates = $this->discovery(['topics' => []])->discover();

        $this->assertTrue($candidates->contains(fn ($c) => str_contains($c['topic'], 'Metadata Viewer') && $c['source'] === 'content_gap'));
    }
}
