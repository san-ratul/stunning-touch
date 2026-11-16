<?php

namespace Tests\Unit;

use App\Models\AutomationSetting;
use App\Models\BlogPost;
use App\Models\Category;
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
