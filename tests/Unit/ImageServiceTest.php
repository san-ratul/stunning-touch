<?php

namespace Tests\Unit;

use App\Models\BlogPost;
use App\Models\ImageSetting;
use App\Models\Media;
use App\Services\Ai\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makePost(): BlogPost
    {
        return BlogPost::create([
            'title' => 'Branded Cover Test Article',
            'slug' => 'branded-cover-test-article',
            'content' => '<h2>Heading</h2><p>Body.</p>',
            'status' => 'draft',
            'source' => 'ai',
        ]);
    }

    public function test_branded_cover_generates_media_and_attaches_to_post(): void
    {
        Storage::fake('public');

        $post = $this->makePost();
        $media = app(ImageService::class)->forPost($post, ImageService::MODE_BRANDED);

        $this->assertNotNull($media, 'GD is available in the test environment.');
        $this->assertEquals('image/png', $media->mime_type);
        $this->assertGreaterThan(1024, $media->size);
        Storage::disk('public')->assertExists($media->file_path);
        $this->assertStringStartsWith('media/covers/', $media->file_path);

        $post->refresh();
        $this->assertEquals($media->file_path, $post->featured_image);
        $this->assertNotNull($post->featured_image_alt);
    }

    public function test_mode_none_generates_nothing(): void
    {
        Storage::fake('public');

        $post = $this->makePost();
        $media = app(ImageService::class)->forPost($post, ImageService::MODE_NONE);

        $this->assertNull($media);
        $this->assertEquals(0, Media::query()->count());
        $this->assertNull($post->featured_image);
    }

    public function test_external_mode_without_configuration_is_skipped(): void
    {
        Storage::fake('public');
        ImageSetting::current()->update(['provider' => 'none']);

        $post = $this->makePost();
        $media = app(ImageService::class)->forPost($post, ImageService::MODE_EXTERNAL_API);

        $this->assertNull($media);
        Http::assertNothingSent();
    }

    public function test_external_mode_generates_from_api_response(): void
    {
        Storage::fake('public');

        // Build a tiny valid PNG to return as b64_json.
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        ImageSetting::current()->update([
            'provider' => 'openai-images',
            'endpoint' => 'https://images.example.com/v1',
            'api_key' => 'img-key',
            'model' => 'image-model',
        ]);

        Http::fake([
            'https://images.example.com/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode($png)]],
            ]),
        ]);

        $post = $this->makePost();
        $media = app(ImageService::class)->forPost($post, ImageService::MODE_EXTERNAL_API);

        $this->assertNotNull($media);
        Storage::disk('public')->assertExists($media->file_path);
        $post->refresh();
        $this->assertEquals($media->file_path, $post->featured_image);

        Http::assertSent(fn ($request) => $request['model'] === 'image-model' && ! isset($request['api_key']));
    }

    public function test_external_api_failure_does_not_block(): void
    {
        Storage::fake('public');

        ImageSetting::current()->update([
            'provider' => 'openai-images',
            'endpoint' => 'https://images.example.com/v1',
            'api_key' => 'img-key',
        ]);

        Http::fake([
            'https://images.example.com/v1/images/generations' => Http::response('error', 500),
        ]);

        $post = $this->makePost();
        $media = app(ImageService::class)->forPost($post, ImageService::MODE_EXTERNAL_API);

        $this->assertNull($media, 'Image failures must never block publishing.');
    }

    // ---- Article-specific prompt engineering ----

    protected function topicPost(string $title, string $focusKeyword, string $content, ?string $slug = null): BlogPost
    {
        return BlogPost::create([
            'title' => $title,
            'slug' => $slug ?? Str::slug($title),
            'content' => $content,
            'status' => 'draft',
            'source' => 'ai',
            'focus_keyword' => $focusKeyword,
        ]);
    }

    public function test_image_prompts_are_article_specific_with_composition_guidance(): void
    {
        $service = app(ImageService::class);

        $mergePost = $this->topicPost(
            'How to Merge Multiple PDF Files Online for Free',
            'merge pdf files',
            '<h2>Merging documents</h2><p>Combine several PDF files into one organized document.</p>'
        );
        $compressPost = $this->topicPost(
            'How to Compress Images Without Losing Quality',
            'compress images',
            '<h2>Smaller files</h2><p>Reduce the file size of your photos.</p>'
        );

        $mergePrompt = $service->buildPrompt($mergePost);
        $compressPrompt = $service->buildPrompt($compressPost);

        // Different topics produce different visual concepts.
        $this->assertNotSame($mergePrompt, $compressPrompt);
        $this->assertStringContainsString('flowing together into one single organized document', $mergePrompt);
        $this->assertStringContainsString('condensing into one small, neat, compact stack', $compressPrompt);

        // Required composition and quality instructions are always present.
        foreach ([$mergePrompt, $compressPrompt] as $prompt) {
            $this->assertStringContainsString('fully framed with generous margins', $prompt);
            $this->assertStringContainsString('one dominant central focal subject', $prompt);
            $this->assertStringContainsString('harmonious modern color palette', $prompt);
            $this->assertStringContainsString('no typography, no lettering, no logos, no watermarks', $prompt);
            $this->assertLessThanOrEqual(1000, strlen($prompt), 'The Cloudflare payload truncates prompts at 1000 chars.');
        }

        // The prompt is grounded in the actual article, but paraphrased
        // without text-prone acronyms (a real generation showed FLUX.1
        // Schnell renders letterforms when "PDF" appears in the prompt).
        $this->assertStringContainsString('How to Merge Multiple Files Online for Free', $mergePrompt);
        $this->assertStringNotContainsString('PDF', $mergePrompt);
    }

    public function test_pdf_to_word_article_gets_transformation_concept(): void
    {
        $service = app(ImageService::class);

        $post = $this->topicPost(
            'How to Convert PDF to Word Online for Free Without Installing Software',
            'convert pdf to word',
            '<h2>Conversion</h2><p>Turn your PDF files into editable documents.</p>'
        );

        $prompt = $service->buildPrompt($post);

        $this->assertStringContainsString('transforming into a different complete document', $prompt);
    }

    public function test_prompt_falls_back_to_focus_keyword_theme_without_topic_match(): void
    {
        $service = app(ImageService::class);

        $post = $this->topicPost(
            'Seasonal Garden Planning Basics',
            'gardening advice',
            '<h2>Planning</h2><p>Plan your garden beds for the season.</p>'
        );

        $prompt = $service->buildPrompt($post);

        $this->assertStringContainsString('gardening advice', $prompt);
    }

    public function test_identical_articles_produce_identical_deterministic_prompts(): void
    {
        $service = app(ImageService::class);

        $first = $this->topicPost(
            'How to Split PDF Files Online',
            'split pdf',
            '<h2>Splitting</h2><p>Split a PDF into separate files.</p>'
        );
        $second = $this->topicPost(
            'How to Split PDF Files Online',
            'split pdf',
            '<h2>Splitting</h2><p>Split a PDF into separate files.</p>',
            'how-to-split-pdf-files-online-2'
        );

        $this->assertSame($service->buildPrompt($first), $service->buildPrompt($second));
    }
}
