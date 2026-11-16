<?php

namespace Tests\Unit;

use App\Models\BlogPost;
use App\Support\Analytics;
use App\Support\SeoResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SupportClassesTest extends TestCase
{
    use RefreshDatabase;

    public function test_seo_resolver_builds_full_titles(): void
    {
        $title = SeoResolver::fullTitle('Image Compressor');
        $this->assertStringContainsString('Image Compressor', $title);
        $this->assertStringContainsString('Stunning Touch', $title);
    }

    public function test_seo_resolver_does_not_duplicate_site_name(): void
    {
        $title = SeoResolver::fullTitle('Free Tools | Stunning Touch');
        $this->assertEquals(1, substr_count($title, 'Stunning Touch'));
    }

    public function test_seo_normalization_fills_fallbacks(): void
    {
        $normalized = SeoResolver::normalize(null, 'Fallback Title', 'Fallback description');

        $this->assertEquals('Fallback Title', $normalized['title']);
        $this->assertEquals('Fallback description', $normalized['description']);
        $this->assertTrue($normalized['robots_index']);
    }

    public function test_seo_absolute_url_handles_relative_and_absolute(): void
    {
        $this->assertEquals('https://stunningtouch.com/a.jpg', SeoResolver::absoluteUrl('https://stunningtouch.com/a.jpg'));
        $this->assertEquals(url('/storage/a.jpg'), SeoResolver::absoluteUrl('/storage/a.jpg'));
        $this->assertNull(SeoResolver::absoluteUrl(null));
    }

    public function test_device_classification(): void
    {
        $mobile = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile']);
        $tablet = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPad; CPU OS 17_0)']);
        $desktop = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 Windows NT 10.0 Chrome/120']);

        $this->assertEquals('mobile', Analytics::classifyDevice($mobile));
        $this->assertEquals('tablet', Analytics::classifyDevice($tablet));
        $this->assertEquals('desktop', Analytics::classifyDevice($desktop));
    }

    public function test_browser_classification(): void
    {
        $make = fn (string $ua) => Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => $ua]);

        $this->assertEquals('Chrome', Analytics::classifyBrowser($make('Mozilla/5.0 Chrome/120.0')));
        $this->assertEquals('Firefox', Analytics::classifyBrowser($make('Mozilla/5.0 Gecko Firefox/121.0')));
        $this->assertEquals('Edge', Analytics::classifyBrowser($make('Mozilla/5.0 Chrome/120 Edg/120')));
        $this->assertEquals('Safari', Analytics::classifyBrowser($make('Mozilla/5.0 Version/17.0 Safari/605.1')));
    }

    public function test_referrer_domain_extraction(): void
    {
        $this->assertEquals('google.com', Analytics::referrerDomain('https://www.google.com/search?q=x'));
        $this->assertNull(Analytics::referrerDomain(null));
        $this->assertNull(Analytics::referrerDomain('not-a-url'));
    }

    public function test_internal_referrer_is_ignored(): void
    {
        config(['app.url' => 'https://stunningtouch.com']);
        $this->assertNull(Analytics::referrerDomain('https://stunningtouch.com/image-compressor'));
        $this->assertNull(Analytics::referrerDomain('https://www.stunningtouch.com/blog'));
    }

    public function test_blog_reading_time_calculation(): void
    {
        $this->assertEquals(1, BlogPost::readingTimeFor('short text'));
        $this->assertEquals(2, BlogPost::readingTimeFor(str_repeat('word ', 300)));
        $this->assertEquals(3, BlogPost::readingTimeFor(str_repeat('word ', 450)));
    }

    public function test_blog_find_tools_for_keywords_matches_existing_tools(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tools = BlogPost::findToolsForKeywords(['compress', 'merge pdf']);

        $this->assertGreaterThan(0, $tools->count());
        $slugs = $tools->pluck('slug');
        $this->assertTrue($slugs->contains('image-compressor'));
        $this->assertTrue($slugs->contains('merge-pdf'));
    }
}
