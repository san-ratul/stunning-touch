<?php

namespace Tests\Unit;

use App\Models\AiGeneration;
use App\Models\AiSetting;
use App\Models\AutomationSetting;
use App\Models\BlogPost;
use App\Models\QualityReport;
use App\Services\Ai\AiProviderService;
use App\Services\Ai\BlogGeneratorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_reports_unconfigured_when_no_settings(): void
    {
        $service = new AiProviderService;

        $this->assertFalse($service->isConfigured());
    }

    public function test_provider_requires_endpoint_model_and_key(): void
    {
        AiSetting::create([
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'is_active' => true,
            // missing model
        ]);

        $service = new AiProviderService;
        $this->assertFalse($service->isConfigured());
    }

    public function test_chat_posts_to_chat_completions_endpoint(): void
    {
        AiSetting::create([
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'model' => 'gpt-test',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Hello from AI']]],
            ]),
        ]);

        $service = new AiProviderService;
        $reply = $service->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertEquals('Hello from AI', $reply);
        Http::assertSent(fn ($request) => $request['model'] === 'gpt-test');
    }

    public function test_chat_fails_loudly_on_api_error(): void
    {
        AiSetting::create([
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'model' => 'gpt-test',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.example.com/v1/chat/completions' => Http::response('denied', 401),
        ]);

        $service = new AiProviderService;

        $this->expectException(\RuntimeException::class);
        $service->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_generation_refuses_without_topics(): void
    {
        AiSetting::create([
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'model' => 'gpt-test',
            'is_active' => true,
        ]);

        $generator = app(BlogGeneratorService::class);
        $result = $generator->generateFromAutomationSettings(manual: true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('topics', strtolower($result['message']));
    }

    public function test_generation_creates_draft_never_publishes_by_default(): void
    {
        $this->seed(DatabaseSeeder::class);

        AiSetting::create([
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'model' => 'gpt-test',
            'is_active' => true,
        ]);

        AutomationSetting::query()->update([
            'is_enabled' => true,
            'auto_publish' => false,
            'topics' => ['How to convert text case online'],
        ]);

        $aiPayload = json_encode([
            'title' => 'How to Convert Text Case Online',
            'slug' => 'how-to-convert-text-case-online',
            'excerpt' => 'A guide to case conversion.',
            'content_html' => '<p>Learn how to convert text case using free tools.</p>',
            'focus_keyword' => 'convert text case',
            'secondary_keywords' => ['case converter'],
            'seo_title' => 'Convert Text Case Online',
            'meta_description' => 'Learn how to convert text case online.',
            'blog_category' => 'Productivity',
            'tags' => ['case', 'text'],
            'related_tools' => ['case-converter'],
            'suggested_image_alt' => 'Case converter tool',
            'outline' => ['Intro'],
        ]);

        Http::fake([
            'https://api.example.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => $aiPayload]],
                ],
            ]),
        ]);

        $generator = app(BlogGeneratorService::class);
        $result = $generator->generateFromAutomationSettings(manual: true);

        $this->assertTrue($result['success'], $result['message']);

        $post = BlogPost::query()->where('slug', 'how-to-convert-text-case-online')->first();
        $this->assertNotNull($post);
        $this->assertEquals('draft', $post->status, 'AI output must default to draft for review.');
        $this->assertEquals('ai', $post->source);
        $this->assertNotNull($post->blog_category_id);
    }

    public function test_generation_failure_is_logged_not_partial(): void
    {
        $this->seed(DatabaseSeeder::class);

        AiSetting::create([
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'model' => 'gpt-test',
            'is_active' => true,
        ]);

        AutomationSetting::query()->update([
            'is_enabled' => true,
            'topics' => ['Another topic'],
        ]);

        Http::fake([
            'https://api.example.com/v1/chat/completions' => Http::response('server error', 500),
        ]);

        $generator = app(BlogGeneratorService::class);
        $failResult = $generator->generateForTopic('Another topic');

        $this->assertFalse($failResult['success']);

        $generation = AiGeneration::query()->where('status', 'failed')->first();
        $this->assertNotNull($generation);
        $this->assertNotNull($generation->error_message);
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

    protected function fakeArticleResponse(array $payload, array $usage = []): void
    {
        Http::fake([
            'https://api.example.com/v1/chat/completions' => Http::response(array_filter([
                'choices' => [['message' => ['content' => json_encode($payload)]]],
                'usage' => $usage ?: null,
            ])),
        ]);

        // Internal-link HTTP validation must not hit the real network.
        Http::fake([
            'stunningtouch.com/*' => Http::response('', 200),
        ]);
    }

    public function test_auto_publish_publishes_when_validation_passes(): void
    {
        $this->seed(DatabaseSeeder::class);

        AiSetting::create([
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'model' => 'gpt-test',
            'is_active' => true,
        ]);

        AutomationSetting::query()->update([
            'is_enabled' => true,
            'auto_publish' => true,
            'topics' => ['How to convert text case online'],
        ]);

        $this->fakeArticleResponse([
            'title' => 'How to Convert Text Case Online',
            'slug' => 'auto-published-article',
            'excerpt' => 'A guide to case conversion.',
            'content_html' => $this->validArticleContent(),
            'focus_keyword' => 'convert text case',
            'secondary_keywords' => ['case converter'],
            'seo_title' => 'Convert Text Case Online',
            'meta_description' => 'Learn how to convert text case online.',
            'blog_category' => 'Productivity',
        ]);

        $result = app(BlogGeneratorService::class)->generateFromAutomationSettings(manual: true);

        $this->assertTrue($result['success'], $result['message']);

        $post = BlogPost::query()->where('slug', 'auto-published-article')->first();
        $this->assertEquals('published', $post->status, 'Validated article should be auto-published when enabled.');
        $this->assertNotNull($post->published_at);

        $report = QualityReport::query()->where('blog_post_id', $post->id)->first();
        $this->assertNotNull($report);
        $this->assertTrue($report->passed);
    }

    public function test_auto_publish_blocked_when_validation_fails(): void
    {
        $this->seed(DatabaseSeeder::class);

        AiSetting::create([
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'model' => 'gpt-test',
            'is_active' => true,
        ]);

        AutomationSetting::query()->update([
            'is_enabled' => true,
            'auto_publish' => true,
            'topics' => ['Short article topic'],
        ]);

        $this->fakeArticleResponse([
            'title' => 'Short Blocked Article',
            'slug' => 'short-blocked-article',
            'excerpt' => 'Too short.',
            'content_html' => '<h2>Heading</h2><p>'.trim(str_repeat('word ', 40)).'</p>',
            'focus_keyword' => 'word',
            'seo_title' => 'Short Article',
            'meta_description' => 'Short description.',
            'blog_category' => 'Productivity',
        ]);

        $result = app(BlogGeneratorService::class)->generateFromAutomationSettings(manual: true);

        $this->assertTrue($result['success'], 'A draft is still created even when auto-publish is blocked.');

        $post = BlogPost::query()->where('slug', 'short-blocked-article')->first();
        $this->assertEquals('draft', $post->status, 'Failed validation must block auto-publish.');
        $this->assertNull($post->published_at);

        $report = QualityReport::query()->where('blog_post_id', $post->id)->first();
        $this->assertNotNull($report);
        $this->assertFalse($report->passed);
    }

    public function test_token_usage_is_recorded_on_generation(): void
    {
        $this->seed(DatabaseSeeder::class);

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

        $this->fakeArticleResponse([
            'title' => 'How to Convert Text Case Online',
            'slug' => 'token-tracked-article',
            'excerpt' => 'A guide to case conversion.',
            'content_html' => $this->validArticleContent(),
            'focus_keyword' => 'convert text case',
            'seo_title' => 'Convert Text Case Online',
            'meta_description' => 'Learn how to convert text case online.',
            'blog_category' => 'Productivity',
        ], ['prompt_tokens' => 321, 'completion_tokens' => 123]);

        $result = app(BlogGeneratorService::class)->generateFromAutomationSettings(manual: true);

        $this->assertTrue($result['success'], $result['message']);

        $generation = AiGeneration::query()->where('status', 'completed')->first();
        $this->assertNotNull($generation);
        $this->assertEquals(321, $generation->prompt_tokens);
        $this->assertEquals(123, $generation->completion_tokens);
    }
}
