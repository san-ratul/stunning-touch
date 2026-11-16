<?php

namespace Tests\Feature;

use App\Models\AiGeneration;
use App\Models\AiSetting;
use App\Models\AutomationSetting;
use App\Models\BlogPost;
use App\Models\BlogResearchSource;
use App\Models\QualityReport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManualBlogGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        $this->seed(DatabaseSeeder::class);

        return User::first();
    }

    protected function enableProvider(array $overrides = []): AiSetting
    {
        return AiSetting::create($overrides + [
            'provider' => 'google-gemini',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'api_key' => 'AIzaSyFakeKeyForFeatureTests00000',
            'auth_mode' => 'bearer',
            'model' => 'gemini-flash-lite-latest',
            'is_active' => true,
            'retry_backoff_ms' => 0,
        ]);
    }

    protected function validPayload(array $overrides = []): array
    {
        $filler = fn (int $words) => trim(str_repeat('content ', $words));

        return $overrides + [
            'title' => 'How to Convert Text Case Online',
            'slug' => 'manual-generated-article',
            'excerpt' => 'A manually generated guide.',
            'content_html' => '<h2>Guide</h2><p>Converting text case matters — this guide shows how to convert text case online. '.$filler(1000).' Try the <a href="https://stunningtouch.com/case-converter">case converter</a>.</p>',
            'focus_keyword' => 'convert text case',
            'secondary_keywords' => ['case converter'],
            'seo_title' => 'Convert Text Case Online',
            'meta_description' => 'Learn how to convert text case online.',
            'blog_category' => 'Productivity',
        ];
    }

    /**
     * Ordered fakes: AI endpoint and internal-link checks first, research
     * catch-all last (first matching stub wins).
     */
    protected function fakeGemini(array $payloadOverrides = []): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode($this->validPayload() + $payloadOverrides)]]],
                'usage' => ['prompt_tokens' => 823, 'completion_tokens' => 1580],
            ]),
            'stunningtouch.com/*' => Http::response('', 200),
            'https://hn.algolia.com/*' => Http::response([
                'hits' => [['objectID' => '11', 'title' => 'Research on text case tools', 'url' => 'https://research.example.com/case']],
            ]),
            '*' => Http::response(''),
        ]);
    }

    protected function manualPayload(array $overrides = []): array
    {
        return $overrides + [
            'topic' => 'How to convert text case online for busy writers',
            'focus_keyword' => 'convert text case',
            'research' => '1',
        ];
    }

    public function test_guests_cannot_access_manual_generation(): void
    {
        $this->get(route('admin.blog.generate.create'))->assertRedirect('/admin/login');
        $this->post(route('admin.blog.generate.store'), [])->assertRedirect('/admin/login');
    }

    public function test_generation_form_renders_with_fields(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.blog.generate.create'))
            ->assertOk()
            ->assertSee('Generate a new blog draft')
            ->assertSee('Topic or content idea')
            ->assertSee('Focus keyword')
            ->assertSee('Generate Draft Article');
    }

    public function test_blog_posts_index_shows_generate_button(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.blog.posts.index'))
            ->assertOk()
            ->assertSee('Generate New Blog');
    }

    public function test_topic_and_focus_keyword_are_required(): void
    {
        $this->enableProvider();

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), ['topic' => '', 'focus_keyword' => ''])
            ->assertRedirect()
            ->assertSessionHasErrors(['topic', 'focus_keyword']);

        $this->assertEquals(0, BlogPost::query()->where('source', 'ai')->count());
    }

    public function test_target_word_count_must_respect_automation_bounds(): void
    {
        $this->enableProvider();

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload(['target_word_count' => 300]))
            ->assertRedirect()
            ->assertSessionHasErrors('target_word_count');

        $this->assertEquals(0, BlogPost::query()->where('source', 'ai')->count());
    }

    public function test_disabled_provider_blocks_generation(): void
    {
        AiSetting::create([
            'provider' => 'google-gemini',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'api_key' => 'AIzaSyFakeKey',
            'model' => 'gemini-flash-lite-latest',
            'auth_mode' => 'bearer',
            'is_active' => false,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload())
            ->assertRedirect()
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'disabled'));

        $this->assertEquals(0, BlogPost::query()->where('source', 'ai')->count());
    }

    public function test_incomplete_provider_blocks_generation(): void
    {
        AiSetting::create([
            'provider' => 'google-gemini',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'model' => 'gemini-flash-lite-latest',
            'auth_mode' => 'bearer',
            'is_active' => true, // enabled but no key stored
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload())
            ->assertRedirect()
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'API key'));

        $this->assertEquals(0, BlogPost::query()->where('source', 'ai')->count());
    }

    public function test_successful_manual_generation_creates_draft_with_provenance_and_report(): void
    {
        $this->enableProvider();
        $this->fakeGemini();
        AutomationSetting::current()->update(['auto_publish' => true]); // even so: never publish

        $response = $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload());

        $response->assertRedirect(route('admin.blog.generate.create'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'draft'));

        $post = BlogPost::query()->where('slug', 'manual-generated-article')->firstOrFail();

        // Always a draft — manual generation never auto-publishes.
        $this->assertEquals('draft', $post->status);
        $this->assertNull($post->published_at);
        $this->assertEquals('ai', $post->source);

        // Quality report persisted.
        $report = QualityReport::query()->where('blog_post_id', $post->id)->firstOrFail();
        $this->assertTrue($report->passed, json_encode($report->violations));
        $this->assertGreaterThan(900, $report->word_count);

        // Research provenance persisted and linked.
        $sources = BlogResearchSource::query()->where('blog_post_id', $post->id)->get();
        $this->assertGreaterThanOrEqual(1, $sources->count());
        $this->assertEquals('https://research.example.com/case', $sources->first()->url);
        $this->assertNotNull($sources->first()->fetched_at);

        // Token usage recorded.
        $generation = AiGeneration::query()->where('blog_post_id', $post->id)->firstOrFail();
        $this->assertEquals('completed', $generation->status);
        $this->assertEquals(823, $generation->prompt_tokens);
        $this->assertEquals(1580, $generation->completion_tokens);

        // Result summary flashed with edit link target.
        $result = session('generation_result');
        $this->assertEquals($post->id, $result['post_id']);
        $this->assertTrue($result['passed']);
    }

    public function test_research_disabled_skips_provenance(): void
    {
        $this->enableProvider();
        $this->fakeGemini();

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload(['research' => null]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $post = BlogPost::query()->where('slug', 'manual-generated-article')->firstOrFail();
        $this->assertEquals(0, BlogResearchSource::query()->where('blog_post_id', $post->id)->count());
    }

    public function test_duplicate_topic_warns_and_requires_explicit_confirmation(): void
    {
        $this->enableProvider();
        $this->fakeGemini();

        BlogPost::create([
            'title' => 'How to convert text case online for busy writers',
            'slug' => 'existing-similar-post',
            'content' => '<h2>Body</h2><p>Text.</p>',
            'status' => 'published',
            'published_at' => now(),
            'focus_keyword' => 'convert text case',
            'source' => 'manual',
        ]);

        // Without the bypass: warned, nothing generated.
        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload())
            ->assertRedirect()
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'similar post already exists'));

        $this->assertDatabaseMissing('blog_posts', ['slug' => 'manual-generated-article']);

        // With the explicit bypass: generation proceeds.
        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload(['force' => '1']))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('blog_posts', ['slug' => 'manual-generated-article']);
    }

    public function test_api_failure_shows_safe_error_and_no_false_success(): void
    {
        $this->enableProvider();
        Http::fake(['*' => Http::response(['error' => ['code' => 503, 'message' => 'high demand']], 503)]);

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload(['research' => null]))
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'overloaded') || str_contains($m, 'Generation failed'));

        $this->assertEquals(0, BlogPost::query()->where('source', 'ai')->count(), 'API failures must not create posts.');

        $generation = AiGeneration::query()->latest('id')->first();
        $this->assertNotNull($generation);
        $this->assertEquals('failed', $generation->status);
    }

    public function test_generation_lock_blocks_parallel_manual_generation(): void
    {
        $this->enableProvider();
        $this->fakeGemini();

        // Another generation holds the shared lock.
        $lock = Cache::lock('ai:generation', 60);
        $this->assertTrue($lock->acquire());

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload())
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'already in progress'));

        $this->assertEquals(0, BlogPost::query()->where('source', 'ai')->count());

        $lock->release();
    }

    public function test_manual_generation_never_auto_publishes_even_with_strict_auto_publish_enabled(): void
    {
        $this->enableProvider();
        $this->fakeGemini();
        AutomationSetting::current()->update(['auto_publish' => true]);

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload());

        $post = BlogPost::query()->where('slug', 'manual-generated-article')->firstOrFail();

        $this->assertEquals('draft', $post->status, 'Manual generation must never publish, even with a passing report.');
        $this->assertNull($post->published_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'post.manual_generated']);
    }

    public function test_prompts_use_the_current_environment_app_url_for_internal_links(): void
    {
        $this->enableProvider();
        config(['app.url' => 'http://stunningtouch.test']);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode($this->validPayload())]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
            ]),
            'http://stunningtouch.test/*' => Http::response('', 200),
            '*' => Http::response(''),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload(['research' => null]))
            ->assertRedirect();

        Http::assertSent(function ($request) {
            $prompt = (string) data_get($request->data(), 'messages.1.content');

            return str_contains($prompt, 'http://stunningtouch.test/{tool-slug}')
                && ! str_contains($prompt, 'stunningtouch.com')
                && str_contains($prompt, '(slug: case-converter');
        });
    }

    // ---- GPT-OSS output budget and token configuration ----

    public function test_admin_max_output_tokens_setting_is_passed_to_the_provider(): void
    {
        $this->enableProvider();
        AiSetting::query()->where('is_active', true)->update(['max_tokens' => 8000]);
        $this->fakeGemini();

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload(['research' => null]))
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions')
            && ($request->data()['max_tokens'] ?? null) === 8000);
    }

    public function test_exhausted_output_budget_never_saves_an_empty_article(): void
    {
        $this->admin(); // Seed first so the post-count baseline is accurate.
        $this->enableProvider();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [['message' => ['content' => '', 'reasoning' => 'invisible reasoning…'], 'finish_reason' => 'length']],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 6000],
                ])
                ->push([
                    'choices' => [['message' => ['content' => '', 'reasoning' => 'still reasoning…'], 'finish_reason' => 'length']],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 12000],
                ]),
            'stunningtouch.com/*' => Http::response('', 200),
        ]);

        $before = BlogPost::query()->count();

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload(['research' => null]))
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'output budget'));

        $this->assertEquals($before, BlogPost::query()->count(), 'No article may be created from an empty response.');

        $generation = AiGeneration::query()->latest('id')->first();
        $this->assertEquals('failed', $generation->status);
        $this->assertStringContainsString('finish_reason=length', $generation->error_message);
    }

    public function test_reasoning_only_response_is_never_saved_as_an_article(): void
    {
        $this->admin(); // Seed first so the post-count baseline is accurate.
        $this->enableProvider();
        AiSetting::query()->where('is_active', true)->update(['max_tokens' => 16000]);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '', 'reasoning' => 'reasoning only, no article']], 'finish_reason' => 'length'],
            ]),
            'stunningtouch.com/*' => Http::response('', 200),
        ]);

        $before = BlogPost::query()->count();

        $this->actingAs($this->admin()) // admin() also seeds; baseline must be taken before seeding.
            ->post(route('admin.blog.generate.store'), $this->manualPayload(['research' => null]))
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'reasoning=present'));

        $this->assertEquals($before, BlogPost::query()->count());
    }

    public function test_total_tokens_include_the_internal_budget_retry(): void
    {
        $this->enableProvider();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 6000],
                ])
                ->push([
                    'choices' => [['message' => ['content' => json_encode($this->validPayload())]], 'finish_reason' => 'stop'],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 12000],
                ]),
            'stunningtouch.com/*' => Http::response('', 200),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload(['research' => null]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $generation = AiGeneration::query()->latest('id')->first();
        $this->assertEquals('completed', $generation->status);
        $this->assertEquals(200, $generation->prompt_tokens, 'Prompt tokens of both attempts must be aggregated.');
        $this->assertEquals(18000, $generation->completion_tokens, 'Completion tokens of both attempts must be aggregated.');
    }

    // ---- Word count adherence and internal linking ----

    public function test_target_word_count_is_passed_to_the_prompt(): void
    {
        $this->enableProvider();
        $this->fakeGemini();

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload(['target_word_count' => 1200]))
            ->assertRedirect();

        Http::assertSent(fn ($request) => str_contains(
            (string) data_get($request->data(), 'messages.1.content'),
            'Write approximately 1200 words'
        ));
    }

    public function test_internal_link_guidance_lists_real_tool_slugs(): void
    {
        $this->enableProvider();
        $this->fakeGemini();

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload())
            ->assertRedirect();

        Http::assertSent(function ($request) {
            $prompt = (string) data_get($request->data(), 'messages.1.content');

            return str_contains($prompt, '(slug: case-converter')
                && str_contains($prompt, 'https://stunningtouch.com/')
                && str_contains($prompt, 'NEVER invent URLs');
        });
    }

    public function test_short_first_response_triggers_bounded_continuation(): void
    {
        $this->enableProvider();

        $short = trim(str_repeat('word ', 200)); // ~200 words: below the 900 minimum
        $long = trim(str_repeat('detail ', 800)); // enough continuation to cross the minimum

        $first = json_encode($this->validPayload([
            'content_html' => '<h2>Guide</h2><p>Learn how to convert text case online. <a href="https://stunningtouch.com/case-converter">case converter</a> and <a href="https://stunningtouch.com/word-counter">word counter</a>.</p><p>'.$short.'</p>',
        ]));
        $continuation = json_encode(['content_html' => '<h2>Advanced workflows</h2><p>'.$long.'</p>']);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => $first]]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200]])
                ->push(['choices' => [['message' => ['content' => $continuation]]], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 400]]),
            'stunningtouch.com/*' => Http::response('', 200),
            '*' => Http::response(''),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $post = BlogPost::query()->where('slug', 'manual-generated-article')->firstOrFail();
        $this->assertStringContainsString('Advanced workflows', $post->content, 'Continuation sections must be appended.');

        $report = QualityReport::query()->where('blog_post_id', $post->id)->latest('id')->first();
        $this->assertTrue($report->passed, json_encode($report->violations));
        $this->assertGreaterThanOrEqual(900, $report->word_count);

        // Token usage aggregated across both calls.
        $generation = AiGeneration::query()->where('blog_post_id', $post->id)->firstOrFail();
        $this->assertEquals(150, $generation->prompt_tokens);
        $this->assertEquals(600, $generation->completion_tokens);

        $aiCalls = 0;
        Http::assertSent(function ($request) use (&$aiCalls) {
            if (str_contains($request->url(), 'chat/completions')) {
                $aiCalls++;
            }

            return true;
        });
        $this->assertSame(2, $aiCalls, 'One initial call plus exactly one continuation.');
    }

    public function test_maximum_continuation_attempts_are_enforced_and_failure_keeps_draft(): void
    {
        $this->enableProvider();
        AutomationSetting::current()->update(['max_generation_continuations' => 2]);

        $short = trim(str_repeat('word ', 200));

        $first = json_encode($this->validPayload([
            'content_html' => '<h2>Guide</h2><p>Learn how to convert text case online. <a href="https://stunningtouch.com/case-converter">case converter</a> and <a href="https://stunningtouch.com/word-counter">word counter</a>.</p><p>'.$short.'</p>',
        ]));
        // Continuations keep coming, but each adds only a few words.
        $stubborn = fn () => json_encode(['content_html' => '<h2>Notes</h2><p>A little more.</p>']);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => $first]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20]])
                ->push(['choices' => [['message' => ['content' => $stubborn()]]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 30]])
                ->push(['choices' => [['message' => ['content' => $stubborn()]]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 30]]),
            'stunningtouch.com/*' => Http::response('', 200),
            '*' => Http::response(''),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload())
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Quality check failed'));

        $aiCalls = 0;
        Http::assertSent(function ($request) use (&$aiCalls) {
            if (str_contains($request->url(), 'chat/completions')) {
                $aiCalls++;
            }

            return true;
        });
        $this->assertSame(3, $aiCalls, 'Initial call plus exactly max_generation_continuations attempts.');

        $post = BlogPost::query()->where('slug', 'manual-generated-article')->firstOrFail();
        $this->assertEquals('draft', $post->status, 'A short article must stay a draft.');

        $report = QualityReport::query()->where('blog_post_id', $post->id)->latest('id')->first();
        $this->assertFalse($report->passed);
        $this->assertTrue(collect($report->violations)->contains(fn ($v) => str_contains($v, 'too short')));

        // Tokens aggregated: 10+5+5 prompt, 20+30+30 completion.
        $generation = AiGeneration::query()->where('blog_post_id', $post->id)->firstOrFail();
        $this->assertEquals(20, $generation->prompt_tokens);
        $this->assertEquals(80, $generation->completion_tokens);
    }

    public function test_empty_continuation_does_not_fabricate_content(): void
    {
        $this->enableProvider();
        AutomationSetting::current()->update(['max_generation_continuations' => 2]);

        $short = trim(str_repeat('word ', 200));

        $first = json_encode($this->validPayload([
            'content_html' => '<h2>Guide</h2><p>Learn how to convert text case online. <a href="https://stunningtouch.com/case-converter">case converter</a>.</p><p>'.$short.'</p>',
        ]));

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => $first]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20]])
                ->push(['choices' => [['message' => ['content' => json_encode(['content_html' => ''])]]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]]),
            'stunningtouch.com/*' => Http::response('', 200),
            '*' => Http::response(''),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), $this->manualPayload())
            ->assertRedirect();

        $aiCalls = 0;
        Http::assertSent(function ($request) use (&$aiCalls) {
            if (str_contains($request->url(), 'chat/completions')) {
                $aiCalls++;
            }

            return true;
        });
        $this->assertSame(2, $aiCalls, 'An empty continuation must stop the loop instead of demanding more calls.');

        $post = BlogPost::query()->where('slug', 'manual-generated-article')->firstOrFail();
        $this->assertEquals('draft', $post->status);
    }
}
