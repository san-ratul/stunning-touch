<?php

namespace Tests\Unit;

use App\Models\BlogPost;
use App\Models\BlogResearchSource;
use App\Models\QualityReport;
use App\Services\Ai\QualityValidatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QualityValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Internal-link HTTP validation must never hit the real network in tests.
        // Note: Http::fake() merges stubs with earlier stubs winning, so tests
        // that need different responses use their own dedicated host pattern.
        Http::fake([
            'stunningtouch.com/*' => Http::response('', 200),
        ]);
    }

    protected function validContent(): string
    {
        $filler = fn (int $words) => trim(str_repeat('content ', $words));

        return '<p>Converting text case online helps writers. '.$filler(120).'</p>'
            .'<h2>Why convert text case matters</h2><p>'.$filler(160).'</p>'
            .'<h3>Uppercase and lowercase</h3><p>'.$filler(160).'</p>'
            .'<h2>Using the case converter tool</h2><p>'.$filler(160).' Try the <a href="https://stunningtouch.com/case-converter">case converter</a> today.</p>'
            .'<h2>Tips for clean formatting</h2><p>'.$filler(160).'</p>'
            .'<h2>Conclusion</h2><p>'.$filler(140).' Converting text case is simple.</p>';
    }

    protected function validPost(array $overrides = []): BlogPost
    {
        return BlogPost::create($overrides + [
            'title' => 'How to Convert Text Case Online',
            'slug' => 'how-to-convert-text-case-online',
            'content' => $this->validContent(),
            'excerpt' => 'A guide to converting text case.',
            'focus_keyword' => 'convert text case',
            'seo_title' => 'Convert Text Case Online for Free',
            'meta_description' => 'Learn how to convert text case online quickly.',
            'status' => 'draft',
            'source' => 'ai',
        ]);
    }

    public function test_valid_article_passes_all_mandatory_checks(): void
    {
        $report = app(QualityValidatorService::class)->validate($this->validPost());

        $this->assertTrue($report->passed, json_encode($report->violations));
        $this->assertSame([], $report->violations);
    }

    public function test_short_content_fails(): void
    {
        $post = $this->validPost(['content' => '<p>Too short.</p><h2>Heading</h2><p>More text.</p>']);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertFalse($report->passed);
        $this->assertStringContainsString('too short', $report->violations[0]);
    }

    public function test_oversized_content_fails(): void
    {
        $post = $this->validPost(['content' => '<h2>Long article</h2><p>'.trim(str_repeat('word ', 2100)).'</p>']);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertFalse($report->passed);
        $this->assertStringContainsString('too long', $report->violations[0]);
    }

    public function test_missing_meta_description_fails(): void
    {
        $post = $this->validPost(['meta_description' => null]);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertFalse($report->passed);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'meta description')));
    }

    public function test_focus_keyword_missing_from_content_fails(): void
    {
        $post = $this->validPost(['focus_keyword' => 'zebra protocols']);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertFalse($report->passed);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'Focus keyword')));
    }

    public function test_focus_keyword_with_reordered_words_passes(): void
    {
        // Derived keywords often reorder topic words and drop short tokens
        // ("generative ai trends" -> "generative trends innovations"), so
        // the literal phrase never appears. Every keyword word being
        // present must count as a pass.
        $post = $this->validPost(['focus_keyword' => 'text case converting']);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertStringNotContainsString('Focus keyword', implode("\n", $report->violations));
    }

    public function test_focus_keyword_with_genuinely_absent_word_fails(): void
    {
        $post = $this->validPost(['focus_keyword' => 'zebra convert text']);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertFalse($report->passed);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'Focus keyword')));
    }

    public function test_h1_heading_fails(): void
    {
        $post = $this->validPost(['content' => '<h1>Page Title</h1>'.$this->validContent()]);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertFalse($report->passed);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'h1')));
    }

    public function test_missing_h2_heading_fails(): void
    {
        $post = $this->validPost(['content' => '<h3>Only a subheading</h3><p>'.trim(str_repeat('word ', 950)).'</p>']);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertFalse($report->passed);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'h2')));
    }

    public function test_unbalanced_html_fails(): void
    {
        $post = $this->validPost(['content' => '<h2>Broken markup</h2><p>Unclosed paragraph<ul><li>item</li></ul>'.$this->validContent()]);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertFalse($report->passed);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'HTML')));
    }

    public function test_duplicate_title_fails(): void
    {
        $this->validPost();

        $duplicate = $this->validPost(['slug' => 'a-different-slug']);

        $report = app(QualityValidatorService::class)->validate($duplicate);

        $this->assertFalse($report->passed);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'Duplicate topic')));
    }

    public function test_report_is_persisted_with_details(): void
    {
        $post = $this->validPost();

        $report = app(QualityValidatorService::class)->validate($post);

        $stored = QualityReport::query()->where('blog_post_id', $post->id)->first();
        $this->assertNotNull($stored);
        $this->assertEquals($report->id, $stored->id);
        $this->assertTrue($stored->passed);
        $this->assertGreaterThan(900, $stored->word_count);
        $this->assertNotEmpty($stored->checks);
    }

    public function test_warnings_do_not_block_passing(): void
    {
        $post = $this->validPost(['excerpt' => null]);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertTrue($report->passed, json_encode($report->violations));
        $this->assertNotEmpty($report->warnings);
    }

    public function test_missing_internal_link_fails(): void
    {
        $content = str_replace('stunningtouch.com/case-converter', 'external.example.com/tool', $this->validContent());
        $post = $this->validPost(['content' => $content]);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertFalse($report->passed);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'no internal links')));
    }

    public function test_production_host_is_internal_when_it_is_the_configured_app_url(): void
    {
        // Regression: with the production APP_URL configured, links written
        // to the production host must be recognized as internal.
        config(['app.url' => 'https://stunningtouch.com']);

        $report = app(QualityValidatorService::class)->validate($this->validPost());

        $this->assertTrue($report->passed, json_encode($report->violations));
        $this->assertTrue(
            collect($report->checks)->firstWhere('name', 'internal_links')['passed'] ?? false
        );
    }

    public function test_local_host_is_internal_and_other_domains_are_external_in_local_environment(): void
    {
        // Environment awareness: with a local APP_URL, local links are
        // internal while production and unrelated domains are external.
        config(['app.url' => 'http://stunningtouch.test']);

        $content = '<h2>Guide</h2><p>'.trim(str_repeat('word ', 1000))
            .' <a href="http://stunningtouch.test/merge-pdf">merge</a>'
            .' <a href="https://stunningtouch.com/split-pdf">split</a>'
            .' <a href="https://other-tools.example.com/tool">other</a></p>';

        $links = app(QualityValidatorService::class)->internalLinks($content);

        $this->assertTrue($links->contains('http://stunningtouch.test/merge-pdf'));
        $this->assertFalse($links->contains('https://stunningtouch.com/split-pdf'), 'Production URLs must not be validated while running locally.');
        $this->assertFalse($links->contains('https://other-tools.example.com/tool'));
        $this->assertCount(1, $links);
    }

    public function test_scheme_and_port_differences_still_match_configured_host(): void
    {
        config(['app.url' => 'https://stunningtouch.com:8443']);

        $content = '<p>'
            .'<a href="http://stunningtouch.com/merge-pdf">http link</a>'
            .' <a href="https://stunningtouch.com:9000/split-pdf">differently ported link</a>'
            .' <a href="/case-converter">relative link</a>'
            .' <a href="https://stunningtouch.test/tool">different host</a>'
            .'</p>';

        $links = app(QualityValidatorService::class)->internalLinks($content);

        $this->assertTrue($links->contains('http://stunningtouch.com/merge-pdf'), 'Scheme must not affect host matching.');
        $this->assertTrue($links->contains('https://stunningtouch.com:9000/split-pdf'), 'Ports must not affect host matching.');
        $this->assertTrue($links->contains('https://stunningtouch.com:8443/case-converter'), 'Relative paths resolve against the configured APP_URL.');
        $this->assertFalse($links->contains('https://stunningtouch.test/tool'));
        $this->assertCount(3, $links);
    }

    public function test_subdomains_are_not_trusted_as_internal(): void
    {
        config(['app.url' => 'https://stunningtouch.com']);

        $links = app(QualityValidatorService::class)->internalLinks(
            '<p><a href="https://evil.stunningtouch.com/trap">trap</a></p>'
        );

        $this->assertCount(0, $links);
    }

    public function test_missing_local_route_fails_validation_with_404(): void
    {
        config(['app.url' => 'http://stunningtouch.test']);
        Http::fake(['stunningtouch.test/*' => Http::response('', 404)]);

        $content = '<p>'.trim(str_repeat('word ', 1000)).' <a href="http://stunningtouch.test/does-not-exist">missing page</a></p>';
        $post = $this->validPost(['content' => $content, 'focus_keyword' => 'word']);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertFalse($report->passed);
        $this->assertTrue(
            collect($report->violations)->contains(fn ($v) => str_contains($v, 'HTTP 404')),
            'A missing route must produce a genuine violation: '.json_encode($report->violations)
        );
    }

    public function test_unreachable_internal_link_is_reported_as_unreachable_not_404(): void
    {
        config(['app.url' => 'http://stunningtouch.test']);
        Http::fake(function () {
            throw new ConnectionException('cURL error 6: could not resolve host');
        });

        $content = '<p>'.trim(str_repeat('word ', 1000)).' <a href="http://stunningtouch.test/merge-pdf">merge</a></p>';
        $post = $this->validPost(['content' => $content, 'focus_keyword' => 'word']);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertFalse($report->passed);
        $this->assertTrue(
            collect($report->violations)->contains(fn ($v) => str_contains($v, 'unreachable')),
            'Network errors must be distinguished from real 404 responses: '.json_encode($report->violations)
        );
        $this->assertFalse(
            collect($report->violations)->contains(fn ($v) => str_contains($v, 'HTTP 404'))
        );
    }

    public function test_broken_internal_link_fails_http_validation(): void
    {
        Http::fake([
            'broken.example.internal/*' => Http::response('Not found', 404),
        ]);

        $content = str_replace('https://stunningtouch.com/case-converter', 'https://broken.example.internal/guide', $this->validContent());
        $post = $this->validPost(['content' => $content]);

        $report = app(QualityValidatorService::class)->validate($post, null, ['internal_hosts' => ['broken.example.internal']]);

        $this->assertFalse($report->passed);
        $this->assertTrue(
            collect($report->violations)->contains(fn ($v) => str_contains($v, 'failed validation')),
            'Violations: '.json_encode($report->violations)
        );
    }

    public function test_unsupported_statistics_fail_without_research_sources(): void
    {
        $content = $this->validContent()
            .'<h2>By the numbers</h2><p>Studies show that image optimization yields 50% faster websites and 73% of users bounce from slow pages.</p>';
        $post = $this->validPost(['content' => $content]);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertFalse($report->passed);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'Unsupported claim')));
    }

    public function test_marketing_percentages_are_not_flagged(): void
    {
        $content = $this->validContent()
            .'<h2>Free forever</h2><p>Our tools are 100% free and run 100% in your browser — no uploads required.</p>';
        $post = $this->validPost(['content' => $content]);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertTrue($report->passed, json_encode($report->violations));
    }

    public function test_statistical_claims_warn_when_research_sources_are_linked(): void
    {
        $content = $this->validContent()
            .'<h2>By the numbers</h2><p>Studies show that image optimization yields 50% faster websites.</p>';
        $post = $this->validPost(['content' => $content]);

        $sources = collect([BlogResearchSource::create([
            'url' => 'https://en.wikipedia.org/wiki/Letter_case',
            'title' => 'Letter case',
            'source_type' => 'wikipedia',
            'fetched_at' => now(),
        ])]);

        $report = app(QualityValidatorService::class)->validate($post, null, ['research_sources' => $sources]);

        $this->assertTrue($report->passed, json_encode($report->violations));
        $this->assertTrue(collect($report->warnings)->contains(fn ($w) => str_contains($w, 'Statistical phrasing')));
    }

    public function test_research_requirement_fails_without_sources(): void
    {
        $post = $this->validPost();

        $report = app(QualityValidatorService::class)->validate($post, null, ['research_required' => true]);

        $this->assertFalse($report->passed);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'Research requirement')));
    }

    public function test_research_requirement_passes_with_sources(): void
    {
        $post = $this->validPost();

        $sources = collect([BlogResearchSource::create([
            'url' => 'https://en.wikipedia.org/wiki/Letter_case',
            'title' => 'Letter case',
            'source_type' => 'wikipedia',
            'fetched_at' => now(),
        ])]);

        $report = app(QualityValidatorService::class)->validate($post, null, ['research_required' => true, 'research_sources' => $sources]);

        $this->assertTrue($report->passed, json_encode($report->violations));
    }

    public function test_readability_warnings_are_recorded(): void
    {
        $longSentence = trim(str_repeat('this is a very long rambling sentence fragment ', 20)).' and it never seems to end.';
        $content = '<h2>Long form</h2><p>'.$longSentence.$this->validContent().'</p>';
        $post = $this->validPost(['content' => $content]);

        $report = app(QualityValidatorService::class)->validate($post);

        $this->assertNotEmpty($report->warnings);
    }
}
