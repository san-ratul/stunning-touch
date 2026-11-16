<?php

namespace Tests\Unit;

use App\Models\BlogPost;
use App\Models\ImageGenerationUsage;
use App\Models\ImageSetting;
use App\Services\Ai\CloudflareImageProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareImageProviderTest extends TestCase
{
    use RefreshDatabase;

    protected const ACCOUNT_ID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected const MODEL = '@cf/black-forest-labs/flux-1-schnell';

    protected function pngBytes(): string
    {
        $image = imagecreatetruecolor(16, 16);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 60, 60));
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
            'account_id' => self::ACCOUNT_ID,
            'api_key' => 'cf-test-token-000000000000',
            'model' => self::MODEL,
            'steps' => 4,
            'daily_limit' => 4,
            'max_retries' => 2,
            'timeout_seconds' => 120,
        ]);

        return ImageSetting::current();
    }

    protected function successResponse(string $png): array
    {
        return ['result' => ['image' => base64_encode($png)], 'success' => true];
    }

    // ---- Configuration ----

    public function test_unconfigured_provider_reports_missing_fields(): void
    {
        ImageSetting::current()->update(['provider' => 'none']);

        $result = app(CloudflareImageProvider::class)->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('provider must be set to "Cloudflare"', $result['message']);
    }

    public function test_token_verify_success(): void
    {
        $this->seedCloudflare();
        Http::fake([
            'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
                'success' => true,
                'result' => ['status' => 'active'],
            ]),
        ]);

        $result = app(CloudflareImageProvider::class)->testConnection();

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertStringContainsString('active', $result['message']);
        $this->assertStringNotContainsString('cf-test-token', $result['message']);
    }

    public function test_token_verify_auth_failure_is_sanitized(): void
    {
        $this->seedCloudflare();
        Http::fake(['*' => Http::response(['success' => false], 401)]);

        $result = app(CloudflareImageProvider::class)->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Authentication failed', $result['message']);
    }

    // ---- API request shape ----

    public function test_correct_url_bearer_and_payload(): void
    {
        $this->seedCloudflare();
        $png = $this->pngBytes();
        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/*' => Http::response($this->successResponse($png)),
        ]);

        $result = app(CloudflareImageProvider::class)->generate('test prompt');

        $this->assertTrue($result['ok'], (string) $result['message']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/'.self::ACCOUNT_ID.'/ai/run/'.self::MODEL
                && $request->header('Authorization')[0] === 'Bearer cf-test-token-000000000000'
                && str_contains(strtolower((string) $request->header('Accept')[0]), 'application/json')
                && $request['prompt'] === 'test prompt'
                && $request['steps'] === 4;
        });
    }

    public function test_steps_are_clamped_to_eight(): void
    {
        $this->seedCloudflare(['steps' => 12]);
        Http::fake(['*' => Http::response($this->successResponse($this->pngBytes()))]);

        app(CloudflareImageProvider::class)->generate('test');

        Http::assertSent(fn ($request) => $request['steps'] === 8);
    }

    // ---- Response parsing ----

    public function test_json_response_is_parsed_and_validated(): void
    {
        $this->seedCloudflare();
        $png = $this->pngBytes();
        Http::fake(['*' => Http::response($this->successResponse($png))]);

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertTrue($result['ok']);
        $this->assertEquals($png, $result['bytes']);
        $this->assertEquals('image/png', $result['mime']);
    }

    public function test_data_uri_prefixed_payload_is_handled(): void
    {
        $this->seedCloudflare();
        $png = $this->pngBytes();
        Http::fake(['*' => Http::response([
            'result' => ['image' => 'data:image/png;base64,'.base64_encode($png)],
            'success' => true,
        ])]);

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertTrue($result['ok']);
        $this->assertEquals($png, $result['bytes']);
    }

    public function test_binary_image_response_is_accepted(): void
    {
        $this->seedCloudflare();
        $png = $this->pngBytes();
        Http::fake(['*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertTrue($result['ok']);
        $this->assertEquals($png, $result['bytes']);
    }

    public function test_malformed_base64_fails_safely(): void
    {
        $this->seedCloudflare();
        Http::fake(['*' => Http::response(['result' => ['image' => '!!!not-valid-base64!!!'], 'success' => true])]);

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('could not be decoded', $result['message']);
    }

    public function test_non_image_payload_fails_validation(): void
    {
        $this->seedCloudflare();
        Http::fake(['*' => Http::response(['result' => ['image' => base64_encode(str_repeat('this is plain text, not an image at all. ', 4))], 'success' => true])]);

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not a valid image', $result['message']);
    }

    public function test_success_envelope_without_image_data_fails(): void
    {
        $this->seedCloudflare();
        Http::fake(['*' => Http::response(['result' => [], 'success' => true])]);

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no image data', $result['message']);
    }

    public function test_error_envelope_is_extracted(): void
    {
        $this->seedCloudflare();
        Http::fake(['*' => Http::response([
            'success' => false,
            'errors' => [['code' => 7502, 'message' => 'flux-1-schnell is not allowed for this account']],
        ])]);

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('7502', $result['message']);
        $this->assertStringContainsString('not allowed for this account', $result['message']);
    }

    // ---- Status handling and retries ----

    public function test_401_fails_immediately_without_retries(): void
    {
        $this->seedCloudflare();
        Http::fake(['*' => Http::response(['errors' => [['message' => 'bad token']]], 401)]);

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Authentication failed', $result['message']);
        $this->assertCount(1, Http::recorded());
    }

    public function test_429_is_retried_then_reported(): void
    {
        $this->seedCloudflare();
        Http::fake(['*' => Http::response(['errors' => [['message' => 'limit']]], 429)]);

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Rate limited', $result['message']);
        $this->assertCount(3, Http::recorded(), '429 must be retried max_retries times.');
    }

    public function test_5xx_is_retried_then_reported(): void
    {
        $this->seedCloudflare();
        Http::fake(['*' => Http::response(['errors' => [['message' => 'oops']]], 503)]);

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Provider error (HTTP 503)', $result['message']);
        $this->assertCount(3, Http::recorded());
    }

    public function test_5xx_is_retried_and_then_succeeds(): void
    {
        $this->seedCloudflare();
        $png = $this->pngBytes();
        Http::fake(['*' => Http::sequence()
            ->push(['errors' => [['message' => 'oops']]], 503)
            ->push($this->successResponse($png))]);

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertTrue($result['ok']);
    }

    public function test_connection_failures_are_retried_then_reported(): void
    {
        $this->seedCloudflare();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            throw new ConnectionException('cURL error 28: timed out');
        });

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Connection failed', $result['message']);
        $this->assertEquals(3, $calls);
    }

    // ---- Quota and usage ----

    public function test_daily_limit_blocks_generation_without_an_api_call(): void
    {
        $this->seedCloudflare();
        Http::fake();

        for ($i = 0; $i < 4; $i++) {
            ImageGenerationUsage::create([
                'provider' => 'cloudflare',
                'status' => 'success',
                'quota_date' => now('UTC')->toDateString(),
            ]);
        }

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Daily image limit reached (4/4)', $result['message']);
        Http::assertNothingSent();
    }

    public function test_four_successes_are_allowed(): void
    {
        $this->seedCloudflare();
        Http::fake(['*' => Http::response($this->successResponse($this->pngBytes()))]);

        for ($i = 0; $i < 4; $i++) {
            $result = app(CloudflareImageProvider::class)->generate('test');
            $this->assertTrue($result['ok'], 'Generation '.($i + 1).' should be within the limit.');
        }

        $fifth = app(CloudflareImageProvider::class)->generate('test');
        $this->assertFalse($fifth['ok']);
        $this->assertStringContainsString('Daily image limit reached (4/4)', $fifth['message']);
    }

    public function test_failed_attempts_do_not_consume_quota(): void
    {
        $this->seedCloudflare();

        for ($i = 0; $i < 3; $i++) {
            ImageGenerationUsage::create([
                'provider' => 'cloudflare',
                'status' => 'failed',
                'error_message' => 'transient',
                'quota_date' => now('UTC')->toDateString(),
            ]);
        }

        Http::fake(['*' => Http::response($this->successResponse($this->pngBytes()))]);

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertTrue($result['ok'], 'Failures must not consume the successful-generation quota.');
    }

    public function test_quota_resets_at_utc_midnight(): void
    {
        $this->seedCloudflare();

        for ($i = 0; $i < 4; $i++) {
            ImageGenerationUsage::create([
                'provider' => 'cloudflare',
                'status' => 'success',
                'quota_date' => now('UTC')->subDay()->toDateString(),
            ]);
        }

        Http::fake(['*' => Http::response($this->successResponse($this->pngBytes()))]);

        $result = app(CloudflareImageProvider::class)->generate('test');

        $this->assertTrue($result['ok'], "Yesterday's usage must not count against today's UTC quota.");
    }

    public function test_usage_is_recorded_with_post_and_model(): void
    {
        $this->seedCloudflare();
        $post = BlogPost::create([
            'title' => 'Usage Tracking Post',
            'slug' => 'usage-tracking-post',
            'content' => '<h2>x</h2><p>y</p>',
            'status' => 'draft',
            'source' => 'ai',
        ]);
        Http::fake(['*' => Http::response($this->successResponse($this->pngBytes()))]);

        app(CloudflareImageProvider::class)->generate('test', $post);

        $row = ImageGenerationUsage::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertEquals('success', $row->status);
        $this->assertEquals($post->id, $row->blog_post_id);
        $this->assertEquals(self::MODEL, $row->model);
        $this->assertEquals(now('UTC')->toDateString(), $row->quota_date->toDateString());
    }

    public function test_monitoring_helpers_report_usage_and_errors(): void
    {
        $this->seedCloudflare();
        ImageGenerationUsage::create([
            'provider' => 'cloudflare',
            'status' => 'success',
            'quota_date' => now('UTC')->toDateString(),
        ]);
        ImageGenerationUsage::create([
            'provider' => 'cloudflare',
            'status' => 'failed',
            'error_message' => 'boom',
            'quota_date' => now('UTC')->toDateString(),
        ]);

        $provider = app(CloudflareImageProvider::class);

        $usage = $provider->usageToday();
        $this->assertEquals(1, $usage['success']);
        $this->assertEquals(1, $usage['failed']);
        $this->assertEquals(3, $usage['remaining']);

        $this->assertNotNull($provider->lastSuccessAt());
        $failures = $provider->recentFailures();
        $this->assertCount(1, $failures);
        $this->assertStringContainsString('boom', $failures[0]['message']);
    }
}
