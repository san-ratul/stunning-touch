<?php

namespace Tests\Feature;

use App\Models\AiGeneration;
use App\Models\AiSetting;
use App\Models\AutomationSetting;
use App\Models\BlogPost;
use App\Models\BlogResearchSource;
use App\Models\PipelineRun;
use App\Services\Ai\BlogAutoPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function enableAutomation(array $overrides = []): AutomationSetting
    {
        $automation = AutomationSetting::current();
        $automation->update($overrides + [
            'is_enabled' => true,
            'timezone' => 'UTC',
            'frequency' => 'daily',
            'posts_per_day' => 1,
            'publish_slots' => ['06:00', '12:00', '18:00', '21:00'],
            'min_word_count' => 900,
            'max_word_count' => 2000,
            'duplicate_threshold' => 85,
            'featured_image_mode' => 'none',
            'research_enabled' => true,
            'auto_publish' => false,
            'topics' => ['How to convert text case online'],
        ]);

        return $automation;
    }

    protected function seedProvider(): void
    {
        AiSetting::create([
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'model' => 'gpt-test',
            'is_active' => true,
            'retry_backoff_ms' => 0,
        ]);
    }

    protected function validArticleContent(): string
    {
        $filler = fn (int $words) => trim(str_repeat('content ', $words));

        return '<p>Converting text case online helps writers. '.$filler(120).'</p>'
            .'<h2>Why convert text case matters</h2><p>'.$filler(160).'</p>'
            .'<h3>Uppercase and lowercase</h3><p>'.$filler(160).'</p>'
            .'<h2>Using the case converter tool</h2><p>'.$filler(160).' Try the <a href="https://stunningtouch.com/case-converter">case converter</a>.</p>'
            .'<h2>Tips for clean formatting</h2><p>'.$filler(160).'</p>'
            .'<h2>Conclusion</h2><p>'.$filler(140).' Converting text case is simple.</p>';
    }

    /**
     * Ordered fakes: first matching stub wins, so the AI endpoint and link
     * checks come first and research catch-all comes last.
     */
    protected function fakePipelineHttp(array $payloadOverrides = []): void
    {
        $payload = $payloadOverrides + [
            'title' => 'How to Convert Text Case Online',
            'slug' => 'pipeline-article',
            'excerpt' => 'A pipeline guide.',
            'content_html' => $this->validArticleContent(),
            'focus_keyword' => 'convert text case',
            'secondary_keywords' => ['case converter'],
            'seo_title' => 'Convert Text Case Online',
            'meta_description' => 'Learn how to convert text case online.',
            'blog_category' => 'Productivity',
        ];

        Http::fake([
            'https://api.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode($payload)]]],
                'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 1200],
            ]),
            'stunningtouch.com/*' => Http::response('', 200),
            'https://hn.algolia.com/*' => Http::response([
                'hits' => [['objectID' => '77', 'title' => 'Research extract about text case tools', 'url' => 'https://research.example.com/case', 'points' => 10]],
            ]),
            '*' => Http::response(''),
        ]);
    }

    public function test_returns_null_when_automation_disabled(): void
    {
        Carbon::setTestNow('2026-11-17 13:00:00');

        $this->assertNull(app(BlogAutoPipeline::class)->run());
    }

    public function test_returns_null_before_any_slot_is_due(): void
    {
        Carbon::setTestNow('2026-11-17 05:30:00');
        $this->enableAutomation()->update(['is_enabled' => true]);

        $this->assertNull(app(BlogAutoPipeline::class)->run());
        $this->assertEquals(0, PipelineRun::count());
        $this->assertNull(AutomationSetting::query()->first()->last_run_at, 'No slot executed, so Last run must stay unset.');
    }

    public function test_executed_slot_updates_last_run_at_even_when_generation_fails(): void
    {
        Carbon::setTestNow('2026-11-17 13:00:00');
        $this->seedProvider();

        Http::fake([
            'https://en.wikipedia.org/*' => Http::response('down', 500),
            'https://hn.algolia.com/*' => Http::response('down', 500),
            '*' => Http::response('down', 500),
        ]);

        $this->enableAutomation();

        $run = app(BlogAutoPipeline::class)->run();

        $this->assertNotNull($run);
        $this->assertEquals('failed', $run->status);
        $this->assertNotNull(
            AutomationSetting::query()->first()->last_run_at,
            'An executed attempt proves the cron is alive even when the run failed.'
        );
    }

    public function test_blocked_auto_publish_records_reason_and_last_run(): void
    {
        Carbon::setTestNow('2026-11-17 13:00:00');
        $this->seedProvider();
        $this->fakePipelineHttp([
            'slug' => 'pipeline-short-article',
            'title' => 'Short Pipeline Article',
            'content_html' => '<h2>Heading</h2><p>'.trim(str_repeat('word ', 40)).'</p>',
            'focus_keyword' => 'word',
        ]);
        $this->enableAutomation(['auto_publish' => true]);

        $run = app(BlogAutoPipeline::class)->run();

        $this->assertNotNull($run);
        $this->assertEquals('completed', $run->status);
        $this->assertFalse($run->published);
        $this->assertStringContainsString(
            'Auto-publish blocked by quality validation',
            (string) $run->error_message,
            'The reason a generated article stayed a draft must be visible to admins.'
        );
        $this->assertNotNull(AutomationSetting::query()->first()->last_run_at);
    }

    public function test_is_published_reflects_manual_publish_after_the_run(): void
    {
        $this->seedProvider();
        $this->fakePipelineHttp([
            'slug' => 'pipeline-later-published',
            'title' => 'Short Pipeline Article Published Later',
            'content_html' => '<h2>Heading</h2><p>'.trim(str_repeat('word ', 40)).'</p>',
            'focus_keyword' => 'word',
        ]);
        $this->enableAutomation(['auto_publish' => true]);

        $run = app(BlogAutoPipeline::class)->run();

        $this->assertNotNull($run);
        $this->assertFalse($run->is_published, 'Blocked run stays unpublished right after finishing.');

        BlogPost::whereKey($run->blog_post_id)->update(['status' => 'published']);

        $this->assertTrue(
            $run->fresh()->is_published,
            'The activity card must reflect manual publishing, not the stale run flag.'
        );
    }

    public function test_generates_draft_for_due_slot_with_research_provenance(): void
    {
        Carbon::setTestNow('2026-11-17 13:00:00');
        $this->seedProvider();
        $this->fakePipelineHttp();
        $this->enableAutomation();

        $run = app(BlogAutoPipeline::class)->run();

        $this->assertNotNull($run);
        $run->refresh();

        $this->assertEquals('completed', $run->status);
        $this->assertEquals('06:00', $run->slot);
        $this->assertFalse($run->published, 'Auto-publish is off; the article must stay a draft.');
        $this->assertEquals('finalized', $run->step);
        $this->assertStringContainsString('How to convert text case online', (string) $run->topic);
        $this->assertEquals('manual', $run->topic_source);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);

        $post = BlogPost::query()->findOrFail($run->blog_post_id);
        $this->assertEquals('draft', $post->status);
        $this->assertEquals('ai', $post->source);

        // Research provenance stored and linked to the article.
        $sources = BlogResearchSource::query()->where('blog_post_id', $post->id)->get();
        $this->assertGreaterThanOrEqual(1, $sources->count());
        $this->assertEquals('https://research.example.com/case', $sources->first()->url);
        $this->assertNotNull($sources->first()->fetched_at);
        $this->assertNotNull($sources->first()->research_run_id);

        // Token usage recorded.
        $generation = AiGeneration::query()->findOrFail($run->ai_generation_id);
        $this->assertEquals(900, $generation->prompt_tokens);
        $this->assertEquals(1200, $generation->completion_tokens);

        // Daily limit (posts_per_day=1) reached: a second invocation does nothing.
        $this->assertNull(app(BlogAutoPipeline::class)->run());
    }

    public function test_auto_publish_publishes_qualified_article(): void
    {
        Carbon::setTestNow('2026-11-17 13:00:00');
        $this->seedProvider();
        $this->fakePipelineHttp();
        $this->enableAutomation(['auto_publish' => true]);

        $run = app(BlogAutoPipeline::class)->run();

        $this->assertNotNull($run);
        $this->assertTrue($run->published);

        $post = BlogPost::query()->findOrFail($run->blog_post_id);
        $this->assertEquals('published', $post->status);
        $this->assertNotNull($post->published_at);
    }

    public function test_auto_publish_blocked_when_validation_fails(): void
    {
        Carbon::setTestNow('2026-11-17 13:00:00');
        $this->seedProvider();
        $this->fakePipelineHttp([
            'slug' => 'pipeline-short-article',
            'title' => 'Short Pipeline Article',
            'content_html' => '<h2>Heading</h2><p>'.trim(str_repeat('word ', 40)).'</p>',
            'focus_keyword' => 'word',
        ]);
        $this->enableAutomation(['auto_publish' => true]);

        $run = app(BlogAutoPipeline::class)->run();

        $this->assertNotNull($run);
        $this->assertEquals('completed', $run->status, 'The pipeline completed; publishing is what failed.');
        $this->assertFalse($run->published, 'Failed validation must block auto-publish.');

        $post = BlogPost::query()->findOrFail($run->blog_post_id);
        $this->assertEquals('draft', $post->status);
    }

    public function test_fails_run_when_research_collects_no_sources(): void
    {
        Carbon::setTestNow('2026-11-17 13:00:00');
        $this->seedProvider();

        Http::fake([
            'https://en.wikipedia.org/*' => Http::response('down', 500),
            'https://hn.algolia.com/*' => Http::response('down', 500),
            '*' => Http::response('down', 500),
        ]);

        $this->enableAutomation();

        $run = app(BlogAutoPipeline::class)->run();

        $this->assertNotNull($run);
        $this->assertEquals('failed', $run->status);
        $this->assertStringContainsString('Research requirement failed', (string) $run->error_message);
        $this->assertNull($run->blog_post_id, 'No AI tokens may be burned without research.');
    }

    public function test_fails_run_when_no_eligible_topic(): void
    {
        Carbon::setTestNow('2026-11-17 13:00:00');
        $this->seedProvider();
        $this->fakePipelineHttp();

        BlogPost::create([
            'title' => 'How to Convert Text Case Online',
            'slug' => 'convert-text-case-online',
            'content' => '<h2>Body</h2><p>Text.</p>',
            'status' => 'published',
            'published_at' => now(),
            'focus_keyword' => 'convert text case',
            'source' => 'manual',
        ]);

        $this->enableAutomation(['topics' => ['How to Convert Text Case Online']]);

        $run = app(BlogAutoPipeline::class)->run();

        $this->assertNotNull($run);
        $this->assertEquals('failed', $run->status);
        $this->assertStringContainsString('No eligible topic', (string) $run->error_message);
    }

    public function test_pipeline_rotates_topics_instead_of_repeating(): void
    {
        $this->seedProvider();

        $payload = fn (string $title, string $slug, string $keyword) => [
            'title' => $title,
            'slug' => $slug,
            'excerpt' => 'A guide.',
            'content_html' => $this->validArticleContent(),
            'focus_keyword' => $keyword,
            'secondary_keywords' => [],
            'seo_title' => $title,
            'meta_description' => 'Learn more about it.',
            'blog_category' => 'Productivity',
        ];

        // Topic-aware fake: each slot generates for the topic the pipeline
        // actually picked, so no fixed title can collide with the other
        // topic during discovery (a shared fixed title would make the
        // second topic appear duplicated regardless of rotation).
        Http::fake(function ($request) use ($payload) {
            if (str_contains((string) $request->url(), 'chat/completions')) {
                $isMerge = str_contains((string) $request->body(), 'Merge PDF files');

                $article = $isMerge
                    ? $payload('Merge PDF Files Without Installing Software', 'rotation-article-merge', 'merge pdf files')
                    : $payload('How to Convert Text Case Online', 'rotation-article-case', 'convert text case');

                return Http::response([
                    'choices' => [['message' => ['content' => json_encode($article)]]],
                    'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 1200],
                ]);
            }

            if (str_contains((string) $request->url(), 'stunningtouch.com')) {
                return Http::response('', 200);
            }

            if (str_contains((string) $request->url(), 'hn.algolia.com')) {
                return Http::response([
                    'hits' => [['objectID' => '77', 'title' => 'Research extract about pdf tools', 'url' => 'https://research.example.com/pdf', 'points' => 10]],
                ]);
            }

            return Http::response('');
        });

        $this->enableAutomation([
            'posts_per_day' => 2,
            'topics' => [
                'How to convert text case online',
                'Merge PDF files without installing software',
            ],
        ]);

        $pipeline = app(BlogAutoPipeline::class);

        Carbon::setTestNow('2026-11-17 13:00:00');
        $first = $pipeline->run();
        $this->assertNotNull($first);
        $this->assertEquals('completed', $first->status);

        Carbon::setTestNow('2026-11-17 19:00:00');
        $second = $pipeline->run();
        $this->assertNotNull($second);
        $this->assertEquals(
            'completed',
            $second->status,
            'RunTwo error: '.(string) $second->error_message.' | runOne topic: '.$first->topic
        );

        $this->assertNotSame(
            trim((string) $first->topic),
            trim((string) $second->topic),
            'The pipeline must rotate topics: a completed slot\'s topic may not be repeated while unused topics remain.'
        );
        $this->assertEquals('06:00', $first->slot);
        $this->assertEquals('12:00', $second->slot);
    }

    public function test_daily_limit_is_enforced_across_slots(): void
    {
        $this->seedProvider();

        $payload = fn (string $title, string $slug, string $keyword) => [
            'title' => $title,
            'slug' => $slug,
            'excerpt' => 'A guide.',
            'content_html' => $this->validArticleContent(),
            'focus_keyword' => $keyword,
            'secondary_keywords' => [],
            'seo_title' => $title,
            'meta_description' => 'Learn more about it.',
            'blog_category' => 'Productivity',
        ];

        // Each slot generates for its own topic: the fake inspects the
        // prompt body and returns a matching payload, so no fixed title can
        // collide with the other topic during discovery.
        Http::fake(function ($request) use ($payload) {
            if (str_contains((string) $request->url(), 'chat/completions')) {
                $isMerge = str_contains((string) $request->body(), 'Merge PDF files');

                $article = $isMerge
                    ? $payload('Merge PDF Files Without Installing Software', 'limit-article-merge', 'merge pdf files')
                    : $payload('How to Convert Text Case Online', 'limit-article-case', 'convert text case');

                return Http::response([
                    'choices' => [['message' => ['content' => json_encode($article)]]],
                    'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 1200],
                ]);
            }

            if (str_contains((string) $request->url(), 'stunningtouch.com')) {
                return Http::response('', 200);
            }

            if (str_contains((string) $request->url(), 'hn.algolia.com')) {
                return Http::response([
                    'hits' => [['objectID' => '77', 'title' => 'Research extract about pdf tools', 'url' => 'https://research.example.com/pdf', 'points' => 10]],
                ]);
            }

            return Http::response('');
        });

        $this->enableAutomation([
            'posts_per_day' => 2,
            'topics' => [
                'How to convert text case online',
                'Merge PDF files without installing software',
            ],
        ]);

        $pipeline = app(BlogAutoPipeline::class);

        Carbon::setTestNow('2026-11-17 13:00:00');
        $runOne = $pipeline->run();
        $this->assertEquals('06:00', $runOne->slot);
        $this->assertEquals(
            'completed',
            $runOne->status,
            'RunOne error: '.(string) $runOne->error_message
        );

        Carbon::setTestNow('2026-11-17 19:00:00');
        $runTwo = $pipeline->run();
        $this->assertEquals('12:00', $runTwo->slot);
        $this->assertEquals(
            'completed',
            $runTwo->status,
            'RunTwo error: '.(string) $runTwo->error_message
        );

        // Daily limit of 2 reached: slot 18:00 is due but must not run.
        Carbon::setTestNow('2026-11-17 21:30:00');
        $this->assertNull($pipeline->run());
    }

    public function test_resumes_after_interrupted_run(): void
    {
        Carbon::setTestNow('2026-11-17 13:00:00');
        $this->seedProvider();
        $this->fakePipelineHttp();
        $this->enableAutomation();

        // Simulate a crashed process mid-generation: a stale running row.
        PipelineRun::create([
            'slot_date' => '2026-11-17',
            'slot' => '06:00',
            'status' => PipelineRun::STATUS_RUNNING,
            'step' => 'generation',
            'completed_steps' => ['topic_selected', 'research', 'keywords'],
            'attempts' => 1,
            'started_at' => now()->subMinutes(10),
        ]);

        $run = app(BlogAutoPipeline::class)->run();

        $this->assertNotNull($run, 'The next tick must resume the interrupted slot.');
        $this->assertEquals('06:00', $run->slot);
        $this->assertEquals(2, $run->attempts);
        $this->assertEquals('completed', $run->status);
        $this->assertEquals(2, PipelineRun::query()->where('slot', '06:00')->whereDate('slot_date', '2026-11-17')->count());
    }

    public function test_slot_retry_cap_exhaustion_moves_to_next_slot(): void
    {
        Carbon::setTestNow('2026-11-17 13:00:00');
        $this->seedProvider();
        $this->fakePipelineHttp();
        $this->enableAutomation(['posts_per_day' => 3]);

        for ($i = 0; $i < 3; $i++) {
            PipelineRun::create([
                'slot_date' => '2026-11-17',
                'slot' => '06:00',
                'status' => PipelineRun::STATUS_FAILED,
                'step' => 'generation',
                'attempts' => $i + 1,
                'error_message' => 'simulated failure',
                'started_at' => now()->subHours(3),
                'finished_at' => now()->subHours(2),
            ]);
        }

        $run = app(BlogAutoPipeline::class)->run();

        $this->assertNotNull($run);
        $this->assertEquals('12:00', $run->slot, 'An exhausted slot must be skipped, not retried forever.');
    }

    public function test_posts_per_day_is_capped_at_four(): void
    {
        $this->enableAutomation(['posts_per_day' => 10]);

        $this->assertEquals(4, app(BlogAutoPipeline::class)->postsPerDay(AutomationSetting::current()));
    }
}
