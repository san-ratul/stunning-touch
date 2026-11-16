<?php

namespace Tests\Feature;

use App\Models\AiGeneration;
use App\Models\AiSetting;
use App\Models\AutomationSetting;
use App\Models\BlogPost;
use App\Models\QualityReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerateDailyBlogCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function seedProvider(): void
    {
        AiSetting::create([
            'provider' => 'openai-compatible',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'model' => 'gpt-test',
            'is_active' => true,
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

    protected function fakeValidResponse(string $slug = 'convert-text-case-guide'): void
    {
        $payload = json_encode([
            'title' => 'How to Convert Text Case Online',
            'slug' => $slug,
            'excerpt' => 'A guide to case conversion.',
            'content_html' => $this->validArticleContent(),
            'focus_keyword' => 'convert text case',
            'secondary_keywords' => ['case converter'],
            'seo_title' => 'Convert Text Case Online',
            'meta_description' => 'Learn how to convert text case online.',
            'blog_category' => 'Productivity',
            'tags' => ['case'],
            'related_tools' => ['case-converter'],
            'suggested_image_alt' => 'Case converter',
            'outline' => ['Intro'],
        ]);

        Http::fake([
            'https://api.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $payload]]],
                'usage' => ['prompt_tokens' => 321, 'completion_tokens' => 123],
            ]),
        ]);

        // Internal-link HTTP validation must not hit the real network.
        Http::fake([
            'stunningtouch.com/*' => Http::response('', 200),
        ]);
    }

    public function test_skips_generation_before_preferred_time(): void
    {
        $this->seedProvider();
        Carbon::setTestNow('2026-11-17 10:00:00'); // 16:00 in Asia/Dhaka

        AutomationSetting::current()->update([
            'is_enabled' => true,
            'preferred_time' => '23:59',
            'timezone' => 'Asia/Dhaka',
            'topics' => ['Some topic'],
        ]);

        $this->artisan('blog:generate-daily')->assertSuccessful();

        $this->assertEquals(0, AiGeneration::count());
        $this->assertEquals(0, BlogPost::count());
    }

    public function test_generates_at_preferred_time_and_records_tokens(): void
    {
        $this->seedProvider();
        Carbon::setTestNow('2026-11-17 10:00:00'); // 16:00 in Asia/Dhaka
        $this->fakeValidResponse();

        AutomationSetting::current()->update([
            'is_enabled' => true,
            'preferred_time' => '15:00',
            'timezone' => 'Asia/Dhaka',
            'topics' => ['How to convert text case online'],
        ]);

        $this->artisan('blog:generate-daily')->assertSuccessful();

        $generation = AiGeneration::query()->firstOrFail();
        $this->assertEquals('completed', $generation->status);
        $this->assertEquals(321, $generation->prompt_tokens);
        $this->assertEquals(123, $generation->completion_tokens);

        $post = BlogPost::query()->where('slug', 'convert-text-case-guide')->firstOrFail();
        $this->assertEquals('draft', $post->status);
        $this->assertEquals('ai', $post->source);
    }

    public function test_does_not_generate_twice_on_the_same_day(): void
    {
        $this->seedProvider();
        Carbon::setTestNow('2026-11-17 10:00:00');
        $this->fakeValidResponse();

        AutomationSetting::current()->update([
            'is_enabled' => true,
            'preferred_time' => '09:00',
            'topics' => ['How to convert text case online'],
        ]);

        $this->artisan('blog:generate-daily')->assertSuccessful();
        $this->artisan('blog:generate-daily')->assertSuccessful();

        $this->assertEquals(1, AiGeneration::query()->where('status', 'completed')->count());
    }

    public function test_weekly_frequency_skips_non_mondays(): void
    {
        $this->seedProvider();
        Carbon::setTestNow('2026-11-17 10:00:00'); // Tuesday

        AutomationSetting::current()->update([
            'is_enabled' => true,
            'frequency' => 'weekly',
            'preferred_time' => '09:00',
            'topics' => ['Some topic'],
        ]);

        $this->artisan('blog:generate-daily')->assertSuccessful();

        $this->assertEquals(0, AiGeneration::count());
    }

    public function test_auto_publish_blocked_when_validation_fails(): void
    {
        $this->seedProvider();
        Carbon::setTestNow('2026-11-17 10:00:00');

        $shortPayload = json_encode([
            'title' => 'Short Blocked Article',
            'slug' => 'short-blocked-article',
            'excerpt' => 'Too short.',
            'content_html' => '<h2>Heading</h2><p>'.trim(str_repeat('word ', 40)).'</p>',
            'focus_keyword' => 'word',
            'seo_title' => 'Short Article',
            'meta_description' => 'Short description.',
            'blog_category' => 'Productivity',
        ]);

        Http::fake([
            'https://api.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $shortPayload]]],
            ]),
        ]);

        AutomationSetting::current()->update([
            'is_enabled' => true,
            'auto_publish' => true,
            'preferred_time' => '09:00',
            'topics' => ['Short blocked article'],
        ]);

        $this->artisan('blog:generate-daily')->assertSuccessful();

        $post = BlogPost::query()->where('slug', 'short-blocked-article')->firstOrFail();
        $this->assertEquals('draft', $post->status, 'Failed validation must block auto-publish.');
        $this->assertNull($post->published_at);

        $report = QualityReport::query()->where('blog_post_id', $post->id)->firstOrFail();
        $this->assertFalse($report->passed);
    }
}
