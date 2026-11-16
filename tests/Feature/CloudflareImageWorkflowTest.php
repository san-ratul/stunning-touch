<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\AutomationSetting;
use App\Models\BlogPost;
use App\Models\ImageGenerationUsage;
use App\Models\ImageSetting;
use App\Models\Media;
use App\Models\User;
use App\Services\Ai\BlogAutoPipeline;
use App\Services\Ai\ImageService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CloudflareImageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        $this->seed(DatabaseSeeder::class);

        return User::first();
    }

    protected function pngBytes(): string
    {
        $image = imagecreatetruecolor(32, 32);
        imagefill($image, 0, 0, imagecolorallocate($image, 90, 120, 200));
        ob_start();
        imagepng($image, null, 9);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    protected function seedCloudflare(array $overrides = []): ImageSetting
    {
        ImageSetting::current()->update($overrides + [
            'provider' => 'cloudflare',
            'account_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
            'api_key' => 'cf-test-token-000000000000',
            'model' => '@cf/black-forest-labs/flux-1-schnell',
            'daily_limit' => 4,
        ]);

        return ImageSetting::current();
    }

    protected function fakeSuccess(): void
    {
        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/*' => Http::response([
                'result' => ['image' => base64_encode($this->pngBytes())],
                'success' => true,
            ]),
        ]);
    }

    protected function makePost(): BlogPost
    {
        return BlogPost::create([
            'title' => 'Cloudflare Image Article',
            'slug' => 'cloudflare-image-article',
            'content' => '<h2>Heading</h2><p>Body text.</p>',
            'status' => 'draft',
            'source' => 'ai',
            'focus_keyword' => 'image test',
        ]);
    }

    // ---- Admin configuration ----

    public function test_cloudflare_settings_persist_with_encrypted_token(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.image-settings.update'), [
                'provider' => 'cloudflare',
                'account_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
                'api_key' => 'cf-secret-token-value-123',
                'model' => '@cf/black-forest-labs/flux-1-schnell',
                'daily_limit' => '4',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $row = ImageSetting::query()->firstOrFail();
        $this->assertEquals('cloudflare', $row->provider);
        $this->assertNotEquals('cf-secret-token-value-123', $row->getRawOriginal('api_key'), 'Token must be encrypted at rest.');
        $this->assertEquals('cf-secret-token-value-123', $row->api_key);
    }

    public function test_blank_token_preserves_stored_token(): void
    {
        $this->seedCloudflare();

        $this->actingAs($this->admin())
            ->put(route('admin.image-settings.update'), [
                'provider' => 'cloudflare',
                'account_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
                'api_key' => '',
                'model' => '@cf/black-forest-labs/flux-1-schnell',
            ])
            ->assertRedirect();

        $this->assertEquals('cf-test-token-000000000000', ImageSetting::current()->api_key);
    }

    public function test_cloudflare_requires_account_id_token_and_cf_model(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.image-settings.update'), ['provider' => 'cloudflare', 'account_id' => 'bad-id', 'api_key' => 'tok', 'model' => 'not-a-cf-model'])
            ->assertRedirect()
            ->assertSessionHasErrors(['account_id', 'model']);

        $this->actingAs($admin)
            ->put(route('admin.image-settings.update'), ['provider' => 'cloudflare', 'account_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'api_key' => '', 'model' => '@cf/black-forest-labs/flux-1-schnell'])
            ->assertRedirect()
            ->assertSessionHasErrors('api_key');

        $this->assertEquals('none', ImageSetting::current()->provider, 'Invalid configuration must not be persisted.');
    }

    public function test_local_provider_still_generates_branded_covers(): void
    {
        Storage::fake('public');
        ImageSetting::current()->update(['provider' => 'none']);

        $post = $this->makePost();
        $media = app(ImageService::class)->forPost($post, ImageService::MODE_BRANDED);

        $this->assertNotNull($media, 'Local GD covers must remain functional.');
        Storage::disk('public')->assertExists($media->file_path);
        $this->assertEquals($media->file_path, $post->refresh()->featured_image);
    }

    // ---- Storage and association ----

    public function test_cloudflare_success_stores_and_associates_image(): void
    {
        Storage::fake('public');
        $this->seedCloudflare();
        $this->fakeSuccess();

        $post = $this->makePost();
        $report = app(ImageService::class)->forPostWithReport($post, ImageService::MODE_CLOUDFLARE);

        $this->assertNotNull($report['media']);
        $this->assertEquals('cloudflare', $report['provider']);
        Storage::disk('public')->assertExists($report['media']->file_path);
        $this->assertEquals($report['media']->file_path, $post->refresh()->featured_image);
        $this->assertNotNull($post->featured_image_alt);

        $usage = ImageGenerationUsage::query()->latest('id')->first();
        $this->assertEquals('success', $usage->status);
        $this->assertEquals($post->id, $usage->blog_post_id);
    }

    public function test_generated_image_is_cropped_to_featured_ratio(): void
    {
        Storage::fake('public');
        $this->seedCloudflare();
        $this->fakeSuccess();

        $post = $this->makePost();
        $media = app(ImageService::class)->forPost($post, ImageService::MODE_CLOUDFLARE);

        $this->assertNotNull($media);
        $stored = Storage::disk('public')->get($media->file_path);
        $info = getimagesizefromstring($stored);
        $this->assertEquals([1200, 630], [$info[0], $info[1]], 'The featured image must be center-cropped to 1200×630 without distortion.');
    }

    public function test_duplicate_generation_is_prevented_until_forced(): void
    {
        Storage::fake('public');
        $this->seedCloudflare();
        $this->fakeSuccess();

        $post = $this->makePost();
        $service = app(ImageService::class);

        $first = $service->forPostWithReport($post, ImageService::MODE_CLOUDFLARE);
        $this->assertNotNull($first['media']);

        $countBefore = ImageGenerationUsage::count();

        // Second call without force: no duplicate generation.
        $second = $service->forPostWithReport($post, ImageService::MODE_CLOUDFLARE);
        $this->assertNull($second['media']);
        $this->assertStringContainsString('already has a featured image', (string) $second['message']);
        $this->assertEquals($countBefore, ImageGenerationUsage::count());

        // Explicit regeneration is allowed.
        $forced = $service->forPostWithReport($post, ImageService::MODE_CLOUDFLARE, force: true);
        $this->assertNotNull($forced['media']);
        $this->assertEquals($countBefore + 1, ImageGenerationUsage::count());
    }

    public function test_regeneration_requires_admin_authorization(): void
    {
        $post = $this->makePost();

        $this->post(route('admin.blog-posts.regenerate-image', $post->id))->assertRedirect('/admin/login');

        $this->actingAs($this->admin())
            ->post(route('admin.blog-posts.regenerate-image', $post->id))
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['action' => 'post.image_regenerated']);
    }

    // ---- Fallback behavior ----

    public function test_cloudflare_failure_falls_back_to_local_cover(): void
    {
        Storage::fake('public');
        $this->seedCloudflare();
        Http::fake(['*' => Http::response(['errors' => [['message' => 'boom']]], 503)]);

        $post = $this->makePost();
        $report = app(ImageService::class)->forPostWithReport($post, ImageService::MODE_CLOUDFLARE);

        $this->assertNotNull($report['media'], 'The local branded cover must be used as fallback.');
        $this->assertEquals('local', $report['provider']);
        $this->assertStringContainsString('local branded cover', (string) $report['message']);
        $this->assertEquals($report['media']->file_path, $post->refresh()->featured_image);
    }

    public function test_exhausted_quota_falls_back_to_local_cover(): void
    {
        Storage::fake('public');
        $this->seedCloudflare();
        Http::fake();

        for ($i = 0; $i < 4; $i++) {
            ImageGenerationUsage::create([
                'provider' => 'cloudflare',
                'status' => 'success',
                'quota_date' => now('UTC')->toDateString(),
            ]);
        }

        $post = $this->makePost();
        $report = app(ImageService::class)->forPostWithReport($post, ImageService::MODE_CLOUDFLARE);

        $this->assertNotNull($report['media']);
        $this->assertEquals('local', $report['provider']);
        $this->assertStringContainsString('Daily image limit reached', (string) $report['message']);
        Http::assertNothingSent();
    }

    public function test_unconfigured_cloudflare_falls_back_to_local_cover(): void
    {
        Storage::fake('public');
        ImageSetting::current()->update(['provider' => 'none']);

        $post = $this->makePost();
        $report = app(ImageService::class)->forPostWithReport($post, ImageService::MODE_CLOUDFLARE);

        $this->assertNotNull($report['media']);
        $this->assertEquals('local', $report['provider']);
        $this->assertStringContainsString('not configured', (string) $report['message']);
    }

    public function test_both_providers_failing_preserves_the_article(): void
    {
        $this->seedCloudflare();
        Http::fake(['*' => Http::response(['errors' => [['message' => 'down']]], 500)]);

        // Force the local cover to fail too via a partial mock.
        $images = \Mockery::mock(ImageService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $images->shouldReceive('generateBrandedCover')->andReturnNull();
        $this->app->instance(ImageService::class, $images);

        $post = $this->makePost();

        $report = $images->forPostWithReport($post, ImageService::MODE_CLOUDFLARE);

        $this->assertNull($report['media']);
        $this->assertStringContainsString('The article is unaffected', (string) $report['message']);

        // The article itself is untouched.
        $post->refresh();
        $this->assertEquals('draft', $post->status);
        $this->assertNull($post->featured_image);
    }

    // ---- Workflows ----

    public function test_manual_generation_reports_cloudflare_image_result(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->seedCloudflare();
        $this->fakeSuccess();
        AutomationSetting::current()->update(['featured_image_mode' => 'cloudflare']);

        AiSetting::create([
            'provider' => 'google-gemini',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'api_key' => 'AIzaSyFakeKey',
            'auth_mode' => 'bearer',
            'model' => 'gemini-flash-lite-latest',
            'is_active' => true,
        ]);

        $filler = trim(str_repeat('content ', 1000));
        $payload = json_encode([
            'title' => 'How to Convert Text Case Online',
            'slug' => 'cloudflare-manual-article',
            'excerpt' => 'Guide.',
            'content_html' => '<h2>Guide</h2><p>Convert text case online. '.$filler.' <a href="https://stunningtouch.com/case-converter">case converter</a></p>',
            'focus_keyword' => 'convert text case',
            'seo_title' => 'Convert Text Case Online',
            'meta_description' => 'Learn how to convert text case online.',
            'blog_category' => 'Productivity',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $payload]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200],
            ]),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), [
                'topic' => 'How to convert text case online',
                'focus_keyword' => 'convert text case',
                'research' => null,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $result = session('generation_result');
        $this->assertTrue($result['image_attached']);
        $this->assertEquals('cloudflare', $result['image_provider']);

        $post = BlogPost::query()->where('slug', 'cloudflare-manual-article')->firstOrFail();
        $this->assertEquals('draft', $post->status);
        $this->assertNotNull($post->featured_image);
    }

    public function test_manual_generation_reports_image_fallback_warning(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->seedCloudflare();
        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/*' => Http::response(['errors' => [['message' => 'capacity']], 503]),
        ]);
        AutomationSetting::current()->update(['featured_image_mode' => 'cloudflare']);

        AiSetting::create([
            'provider' => 'google-gemini',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'api_key' => 'AIzaSyFakeKey',
            'auth_mode' => 'bearer',
            'model' => 'gemini-flash-lite-latest',
            'is_active' => true,
        ]);

        $filler = trim(str_repeat('content ', 1000));
        $payload = json_encode([
            'title' => 'How to Convert Text Case Online',
            'slug' => 'fallback-manual-article',
            'excerpt' => 'Guide.',
            'content_html' => '<h2>Guide</h2><p>Convert text case online. '.$filler.' <a href="https://stunningtouch.com/case-converter">case converter</a></p>',
            'focus_keyword' => 'convert text case',
            'seo_title' => 'Convert Text Case Online',
            'meta_description' => 'Learn how to convert text case online.',
            'blog_category' => 'Productivity',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $payload]]],
            ]),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), [
                'topic' => 'How to convert text case online',
                'focus_keyword' => 'convert text case',
                'research' => null,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $result = session('generation_result');
        $this->assertTrue($result['image_attached']);
        $this->assertEquals('local', $result['image_provider']);
        $this->assertStringContainsString('Cloudflare image generation failed', (string) $result['image_warning']);

        $post = BlogPost::query()->where('slug', 'fallback-manual-article')->firstOrFail();
        $this->assertEquals('draft', $post->status, 'Image failure must not affect the article.');
    }

    public function test_autonomous_pipeline_uses_cloudflare_with_fallback(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->seedCloudflare();

        AiSetting::create([
            'provider' => 'google-gemini',
            'endpoint' => 'https://api.example.com/v1',
            'api_key' => 'sk-test',
            'auth_mode' => 'bearer',
            'model' => 'gpt-test',
            'is_active' => true,
            'retry_backoff_ms' => 0,
        ]);

        AutomationSetting::current()->update([
            'is_enabled' => true,
            'timezone' => 'UTC',
            'posts_per_day' => 1,
            'topics' => ['How to convert text case online'],
            'featured_image_mode' => 'cloudflare',
            'research_enabled' => false,
        ]);

        $filler = trim(str_repeat('content ', 1000));
        $payload = json_encode([
            'title' => 'How to Convert Text Case Online',
            'slug' => 'pipeline-cloudflare-article',
            'excerpt' => 'Guide.',
            'content_html' => '<h2>Guide</h2><p>Convert text case online. '.$filler.' <a href="https://stunningtouch.com/case-converter">case converter</a></p>',
            'focus_keyword' => 'convert text case',
            'seo_title' => 'Convert Text Case Online',
            'meta_description' => 'Learn how to convert text case online.',
            'blog_category' => 'Productivity',
        ]);

        // Ordered fakes: first matching stub wins, so the AI endpoint and
        // internal-link checks come first and the catch-all comes last.
        Http::fake([
            'https://api.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $payload]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200],
            ]),
            'stunningtouch.com/*' => Http::response('', 200),
            'https://api.cloudflare.com/client/v4/accounts/*' => Http::response(['errors' => [['message' => 'capacity']], 503]),
            '*' => Http::response(''),
        ]);

        $this->travelTo(now()->setTime(13, 0));

        $run = app(BlogAutoPipeline::class)->run();

        $this->assertNotNull($run);
        $this->assertEquals(
            'completed',
            $run->status,
            'Pipeline run error: '.(string) $run->error_message
        );
        $this->assertFalse($run->published, 'Auto-publish is off; the pipeline must keep the draft.');

        $post = BlogPost::query()->findOrFail($run->blog_post_id);
        $this->assertEquals('draft', $post->status);
        $this->assertNotNull($post->featured_image, 'The fallback local cover must be attached.');
        $this->assertDatabaseHas('image_generation_usage', ['status' => 'failed', 'blog_post_id' => $post->id]);
    }

    public function test_saving_cloudflare_provider_switches_featured_image_mode(): void
    {
        $this->seed(DatabaseSeeder::class);
        AutomationSetting::current()->update(['featured_image_mode' => 'branded']);

        $this->actingAs($this->admin())
            ->put(route('admin.image-settings.update'), [
                'provider' => 'cloudflare',
                'account_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
                'api_key' => 'cf-secret-token-value-123',
                'model' => '@cf/black-forest-labs/flux-1-schnell',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'cloudflare'));

        $this->assertEquals('cloudflare', AutomationSetting::current()->featured_image_mode);
    }

    public function test_manual_generation_uses_cloudflare_after_provider_is_saved_without_touching_mode(): void
    {
        // Regression for the real incident: Cloudflare credentials were
        // saved while featured_image_mode stayed "branded", so manual
        // generation kept producing local covers.
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        AutomationSetting::current()->update(['featured_image_mode' => 'branded']);

        $this->actingAs($this->admin())
            ->put(route('admin.image-settings.update'), [
                'provider' => 'cloudflare',
                'account_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
                'api_key' => 'cf-secret-token-value-123',
                'model' => '@cf/black-forest-labs/flux-1-schnell',
            ])
            ->assertRedirect();

        $this->fakeSuccess();

        AiSetting::create([
            'provider' => 'google-gemini',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'api_key' => 'AIzaSyFakeKey',
            'auth_mode' => 'bearer',
            'model' => 'gemini-flash-lite-latest',
            'is_active' => true,
        ]);

        $filler = trim(str_repeat('content ', 1000));
        $payload = json_encode([
            'title' => 'How to Convert Text Case Online',
            'slug' => 'provider-sync-article',
            'excerpt' => 'Guide.',
            'content_html' => '<h2>Guide</h2><p>Convert text case online. '.$filler.' <a href="https://stunningtouch.com/case-converter">case converter</a></p>',
            'focus_keyword' => 'convert text case',
            'seo_title' => 'Convert Text Case Online',
            'meta_description' => 'Learn how to convert text case online.',
            'blog_category' => 'Productivity',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $payload]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200],
            ]),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.blog.generate.store'), [
                'topic' => 'How to convert text case online',
                'focus_keyword' => 'convert text case',
                'research' => null,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $result = session('generation_result');
        $this->assertTrue($result['image_attached']);
        $this->assertEquals('cloudflare', $result['image_provider'], 'Manual generation must use Cloudflare once the provider is saved, without the admin having to change the mode separately.');
    }

    // ---- Regeneration ----

    protected function seedPreviousImage(BlogPost $post): Media
    {
        Storage::disk('public')->put('media/covers/previous.png', 'previous-image-bytes');

        return Media::query()->create([
            'user_id' => null,
            'file_name' => 'previous.png',
            'file_path' => 'media/covers/previous.png',
            'disk' => 'public',
            'mime_type' => 'image/png',
            'size' => 20,
            'alt_text' => 'Previous cover',
        ]);
    }

    public function test_failed_forced_regeneration_keeps_previous_image(): void
    {
        Storage::fake('public');
        $this->seedCloudflare();
        Http::fake(['*' => Http::response(['errors' => [['message' => 'down']]], 503)]);

        $post = $this->makePost();
        $previous = $this->seedPreviousImage($post);
        $post->update(['featured_image' => $previous->file_path, 'featured_image_alt' => 'Previous cover']);

        $report = app(ImageService::class)->forPostWithReport($post, ImageService::MODE_CLOUDFLARE, force: true);

        $this->assertNull($report['media'], 'A failed regeneration must not create a fallback replacement.');
        $this->assertNull($report['provider']);
        $this->assertStringContainsString('previous featured image was kept', (string) $report['message']);
        $this->assertStringContainsString('Cloudflare image generation failed', (string) $report['message']);

        $post->refresh();
        $this->assertEquals($previous->file_path, $post->featured_image, 'The previous image must be preserved.');
        $this->assertEquals(1, Media::query()->count(), 'No fallback branded cover may be created during a failed regeneration.');
        $this->assertDatabaseHas('image_generation_usage', ['status' => 'failed', 'blog_post_id' => $post->id]);
    }

    public function test_forced_regeneration_when_quota_exhausted_keeps_previous_image(): void
    {
        Storage::fake('public');
        $this->seedCloudflare();
        Http::fake();

        for ($i = 0; $i < 4; $i++) {
            ImageGenerationUsage::create([
                'provider' => 'cloudflare',
                'status' => 'success',
                'quota_date' => now('UTC')->toDateString(),
            ]);
        }

        $post = $this->makePost();
        $previous = $this->seedPreviousImage($post);
        $post->update(['featured_image' => $previous->file_path]);

        $report = app(ImageService::class)->forPostWithReport($post, ImageService::MODE_CLOUDFLARE, force: true);

        $this->assertNull($report['media']);
        $this->assertStringContainsString('Daily image limit reached', (string) $report['message']);
        $this->assertEquals($previous->file_path, $post->refresh()->featured_image);
        Http::assertNothingSent();
    }

    public function test_forced_regeneration_with_cloudflare_success_replaces_image(): void
    {
        Storage::fake('public');
        $this->seedCloudflare();
        $this->fakeSuccess();

        $post = $this->makePost();
        $previous = $this->seedPreviousImage($post);
        $post->update(['featured_image' => $previous->file_path]);

        $report = app(ImageService::class)->forPostWithReport($post, ImageService::MODE_CLOUDFLARE, force: true);

        $this->assertNotNull($report['media']);
        $this->assertEquals('cloudflare', $report['provider']);

        $post->refresh();
        $this->assertNotEquals($previous->file_path, $post->featured_image);
        $this->assertEquals($report['media']->file_path, $post->featured_image);

        // The replacement was stored successfully before reassociation.
        Storage::disk('public')->assertExists($report['media']->file_path);
        Storage::disk('public')->assertExists($previous->file_path);
    }

    public function test_cloudflare_prompt_is_article_specific(): void
    {
        Storage::fake('public');
        $this->seedCloudflare();
        $this->fakeSuccess();

        $post = $this->makePost();
        $post->update(['title' => 'How to Merge PDF Files Online', 'focus_keyword' => 'merge pdf']);

        app(ImageService::class)->forPost($post, ImageService::MODE_CLOUDFLARE);

        Http::assertSent(function ($request) {
            $prompt = (string) $request['prompt'];

            return str_contains($prompt, 'Visual concept:')
                && str_contains($prompt, 'flowing together into one single organized document')
                && str_contains($prompt, 'fully framed with generous margins')
                && str_contains($prompt, 'How to Merge Files Online')
                && ! str_contains($prompt, 'PDF');
        });
    }

    public function test_monitoring_page_shows_image_metrics(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedCloudflare();
        Http::fake(['*' => Http::response('', 500)]);

        ImageGenerationUsage::create([
            'provider' => 'cloudflare',
            'status' => 'success',
            'quota_date' => now('UTC')->toDateString(),
        ]);
        ImageGenerationUsage::create([
            'provider' => 'cloudflare',
            'status' => 'failed',
            'error_message' => 'HTTP 500 boom',
            'quota_date' => now('UTC')->toDateString(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.monitoring.index'))
            ->assertOk()
            ->assertSee('Image generation (Cloudflare)')
            ->assertSee('1 / 4 generated')
            ->assertSee('HTTP 500 boom');
    }
}
