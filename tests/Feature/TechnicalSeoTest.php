<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Tool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Technical SEO improvements:
 * canonical host redirects, robots/sitemap canonicalization, OG/Twitter
 * fallbacks, HSTS, homepage meta description and favicon declarations.
 *
 * The test suite runs with APP_URL=https://stunningtouch.com (phpunit.xml),
 * which is the canonical domain, so generated URLs can be asserted exactly.
 */
class TechnicalSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    // ------------------------------------------------------------------
    // Canonical URL redirects (RedirectCanonicalHost middleware)
    // ------------------------------------------------------------------

    public function test_www_host_is_redirected_to_canonical_in_a_single_hop(): void
    {
        $response = $this->get('http://www.stunningtouch.com/image-resizer?tool=1');

        $response->assertStatus(301);
        $response->assertLocation('https://stunningtouch.com/image-resizer?tool=1');
    }

    public function test_https_www_host_is_redirected_to_canonical(): void
    {
        $response = $this->get('https://www.stunningtouch.com/');

        $response->assertStatus(301);
        $response->assertLocation('https://stunningtouch.com/');
    }

    public function test_redirect_preserves_paths_and_query_strings(): void
    {
        $response = $this->get('http://www.stunningtouch.com/blog/category/tools?page=2&sort=new');

        $response->assertStatus(301);
        $this->assertStringStartsWith('https://stunningtouch.com/blog/category/tools', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('page=2', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('sort=new', (string) $response->headers->get('Location'));
    }

    public function test_canonical_https_host_is_not_redirected(): void
    {
        $this->get('https://stunningtouch.com/')->assertStatus(200);
        $this->get('https://stunningtouch.com/image-resizer')->assertStatus(200);
    }

    public function test_unrelated_hostnames_are_never_redirected(): void
    {
        // Staging domains, localhost and IP access must keep working untouched.
        $this->get('https://staging.example.com/')->assertStatus(200);
        $this->get('http://127.0.0.1:8899/')->assertStatus(200);
    }

    public function test_htaccess_layers_carry_the_canonical_and_https_rules(): void
    {
        foreach ([
            public_path('.htaccess'),
            base_path('deploy/webroot-htaccess.txt'),
            base_path('deploy/root-public-htaccess.txt'),
        ] as $file) {
            $content = file_get_contents($file);

            // www -> apex, single hop
            $this->assertStringContainsString('RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]', $content, $file);
            $this->assertStringContainsString('RewriteRule ^ https://%1%{REQUEST_URI} [R=301,L,NE]', $content, $file);

            // HTTPS upgrade guarded by both the connection scheme and the
            // proxy-reported protocol, so it never loops behind a proxy.
            $this->assertStringContainsString('RewriteCond %{HTTPS} !=on', $content, $file);
            $this->assertStringContainsString('RewriteCond %{HTTP:X-Forwarded-Proto} !https [NC]', $content, $file);
        }
    }

    // ------------------------------------------------------------------
    // Canonical tags and sitemap host-independence
    // ------------------------------------------------------------------

    public function test_canonical_tags_use_the_canonical_host_regardless_of_request_host(): void
    {
        $content = $this->get('https://mirror.example.com/image-resizer')->getContent();

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://stunningtouch.com/image-resizer">',
            $content,
        );
        $this->assertStringContainsString(
            '<meta property="og:url" content="https://stunningtouch.com/image-resizer">',
            $content,
        );
    }

    public function test_sitemap_urls_always_use_the_canonical_host(): void
    {
        $content = $this->get('https://mirror.example.com/sitemap.xml')->getContent();

        $this->assertStringContainsString('<loc>https://stunningtouch.com/</loc>', $content);
        $this->assertStringContainsString('<loc>https://stunningtouch.com/image-resizer</loc>', $content);
        $this->assertStringNotContainsString('https://www.', $content);
        $this->assertStringNotContainsString('mirror.example.com', $content);
    }

    public function test_robots_route_points_at_the_canonical_sitemap(): void
    {
        $content = $this->get('/robots.txt')->getContent();

        $this->assertStringContainsString('Sitemap: https://stunningtouch.com/sitemap.xml', $content);
    }

    // ------------------------------------------------------------------
    // robots.txt static file (shipped, shadows the route on the server)
    // ------------------------------------------------------------------

    public function test_shipped_static_robots_txt_has_the_sitemap_directive_exactly_once(): void
    {
        $content = file_get_contents(public_path('robots.txt'));

        $this->assertSame(1, substr_count($content, 'Sitemap: https://stunningtouch.com/sitemap.xml'));
        $this->assertStringContainsString('User-agent: *', $content);
        $this->assertStringContainsString('Allow: /', $content);
        $this->assertStringContainsString('Disallow: /admin', $content);
        $this->assertStringContainsString('Disallow: /admin/*', $content);
        $this->assertStringContainsString('Disallow: /api/*', $content);
        $this->assertStringContainsString('Disallow: /run-scheduler/*', $content);
    }

    public function test_robots_route_lists_the_sitemap_directive_exactly_once(): void
    {
        $content = $this->get('/robots.txt')->getContent();

        $this->assertSame(1, substr_count($content, 'Sitemap:'));
        $this->assertSame(1, substr_count($content, 'Disallow: /admin/*'));
    }

    // ------------------------------------------------------------------
    // Open Graph / Twitter metadata
    // ------------------------------------------------------------------

    public function test_home_falls_back_to_the_default_og_image(): void
    {
        $content = $this->get('/')->getContent();

        $this->assertStringContainsString('<meta property="og:image" content="https://stunningtouch.com/og-image.png">', $content);
        $this->assertStringContainsString('<meta property="og:image:width" content="1200">', $content);
        $this->assertStringContainsString('<meta property="og:image:height" content="630">', $content);
        $this->assertStringContainsString('<meta property="og:image:type" content="image/png">', $content);
        $this->assertStringContainsString('<meta name="twitter:image" content="https://stunningtouch.com/og-image.png">', $content);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $content);
    }

    public function test_meta_tags_are_not_duplicated(): void
    {
        $content = $this->get('/')->getContent();

        $this->assertSame(1, substr_count($content, 'property="og:image" content='));
        $this->assertSame(1, substr_count($content, 'property="og:title"'));
        $this->assertSame(1, substr_count($content, 'property="og:description"'));
        $this->assertSame(1, substr_count($content, 'name="twitter:image"'));
        $this->assertSame(1, substr_count($content, 'name="twitter:card"'));
        $this->assertSame(1, substr_count($content, '<link rel="canonical"'));
    }

    public function test_page_specific_og_image_takes_precedence_over_the_default(): void
    {
        $tool = Tool::first();
        $tool->seo()->updateOrCreate([], ['og_image' => 'media/tool-cover.png']);

        $content = $this->get('/'.$tool->slug)->getContent();

        $this->assertStringContainsString('<meta property="og:image" content="https://stunningtouch.com/media/tool-cover.png">', $content);
        // Dimensions metadata is only known for the bundled default image.
        $this->assertStringNotContainsString('og:image:width', $content);
        // Twitter inherits the og:image when no twitter:image is set.
        $this->assertStringContainsString('<meta name="twitter:image" content="https://stunningtouch.com/media/tool-cover.png">', $content);
    }

    public function test_twitter_image_overrides_the_og_image(): void
    {
        $tool = Tool::first();
        $tool->seo()->updateOrCreate([], [
            'og_image' => 'media/og.png',
            'twitter_image' => 'media/twitter.png',
        ]);

        $content = $this->get('/'.$tool->slug)->getContent();

        $this->assertStringContainsString('<meta property="og:image" content="https://stunningtouch.com/media/og.png">', $content);
        $this->assertStringContainsString('<meta name="twitter:image" content="https://stunningtouch.com/media/twitter.png">', $content);
    }

    public function test_og_and_twitter_description_fallbacks(): void
    {
        $tool = Tool::first();
        $tool->seo()->updateOrCreate([], [
            'meta_description' => 'Standard description.',
            'og_description' => 'Open graph description.',
        ]);

        $content = $this->get('/'.$tool->slug)->getContent();

        $this->assertStringContainsString('content="Open graph description."', $content);
        // twitter:description falls back to og:description, then description.
        $this->assertStringContainsString('<meta name="twitter:description" content="Open graph description.">', $content);
    }

    public function test_twitter_description_overrides_the_og_description(): void
    {
        $tool = Tool::first();
        $tool->seo()->updateOrCreate([], [
            'meta_description' => 'Standard description.',
            'og_description' => 'Open graph description.',
            'twitter_description' => 'Twitter description.',
        ]);

        $content = $this->get('/'.$tool->slug)->getContent();

        $this->assertStringContainsString('<meta property="og:description" content="Open graph description.">', $content);
        $this->assertStringContainsString('<meta name="twitter:description" content="Twitter description.">', $content);
    }

    public function test_blog_post_falls_back_to_its_featured_image_for_the_og_image(): void
    {
        BlogPost::create([
            'title' => 'Featured Cover Post',
            'slug' => 'featured-cover-post',
            'content' => '<p>x</p>',
            'featured_image' => 'posts/cover.jpg',
            'status' => 'published',
            'published_at' => now(),
            'reading_time' => 1,
        ]);

        $content = $this->get('/blog/featured-cover-post')->getContent();

        $this->assertStringContainsString('<meta property="og:image" content="https://stunningtouch.com/posts/cover.jpg">', $content);
        $this->assertStringNotContainsString('og-image.png', $content);
    }

    public function test_default_og_image_asset_exists_with_the_expected_dimensions(): void
    {
        $path = public_path('og-image.png');

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));

        [$width, $height] = getimagesize($path);
        $this->assertSame(1200, $width);
        $this->assertSame(630, $height);
    }

    // ------------------------------------------------------------------
    // HSTS header
    // ------------------------------------------------------------------

    public function test_hsts_is_sent_exactly_once_on_https_responses(): void
    {
        $response = $this->get('https://stunningtouch.com/');

        $response->assertStatus(200);
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->assertSame(1, count($response->headers->all('Strict-Transport-Security')));

        // Deliberately no includeSubDomains / preload.
        $this->assertStringNotContainsString('includeSubDomains', (string) $response->headers->get('Strict-Transport-Security'));
        $this->assertStringNotContainsString('preload', (string) $response->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_is_not_sent_on_plain_http_responses(): void
    {
        $response = $this->get('http://stunningtouch.com/');

        $response->assertStatus(200);
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function test_existing_security_headers_are_preserved(): void
    {
        $response = $this->get('https://stunningtouch.com/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    // ------------------------------------------------------------------
    // Homepage meta description
    // ------------------------------------------------------------------

    public function test_homepage_meta_description_is_complete_and_within_limits(): void
    {
        $content = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression(
            '/<meta name="description" content="([^"]+)">/',
            $content,
            'The homepage must render a meta description.',
        );

        preg_match('/<meta name="description" content="([^"]+)">/', $content, $matches);
        $description = $matches[1];

        $this->assertGreaterThanOrEqual(140, mb_strlen($description));
        $this->assertLessThanOrEqual(155, mb_strlen($description));
        // No mid-sentence truncation: the raw copy is short enough to
        // survive the seo-head Str::limit() safety net untouched.
        $this->assertStringNotContainsString('…', $description);
    }

    // ------------------------------------------------------------------
    // Favicons
    // ------------------------------------------------------------------

    public function test_layout_declares_svg_ico_and_apple_touch_icons(): void
    {
        // Note: in this application's test context every generated URL is
        // rooted at the canonical APP_URL, so asset() renders canonical links.
        $content = $this->get('/')->getContent();

        $this->assertStringContainsString('<link rel="icon" href="https://stunningtouch.com/favicon.svg" type="image/svg+xml">', $content);
        $this->assertStringContainsString('<link rel="icon" href="https://stunningtouch.com/favicon.ico" sizes="any">', $content);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="https://stunningtouch.com/apple-touch-icon.png">', $content);
        $this->assertSame(1, substr_count($content, '<link rel="icon" href="https://stunningtouch.com/favicon.svg"'));
    }

    public function test_referenced_favicon_assets_exist_and_are_valid(): void
    {
        foreach (['favicon.svg', 'favicon.ico', 'apple-touch-icon.png'] as $asset) {
            $path = public_path($asset);
            $this->assertFileExists($path, "Missing favicon asset: {$asset}");
            $this->assertGreaterThan(0, filesize($path), "Favicon asset must not be empty: {$asset}");
        }

        [$width, $height] = getimagesize(public_path('apple-touch-icon.png'));
        $this->assertSame(180, $width);
        $this->assertSame(180, $height);
    }

    // ------------------------------------------------------------------
    // Regression: key surfaces stay reachable
    // ------------------------------------------------------------------

    public function test_public_and_admin_surfaces_stay_functional(): void
    {
        $this->get('https://stunningtouch.com/')->assertOk();
        $this->get('https://stunningtouch.com/tools')->assertOk();
        $this->get('https://stunningtouch.com/image-resizer')->assertOk();
        $this->get('https://stunningtouch.com/sitemap.xml')->assertOk();
        $this->get('https://stunningtouch.com/robots.txt')->assertOk();

        $this->get('https://stunningtouch.com/admin')->assertRedirect();
        $this->get('https://stunningtouch.com/admin/login')->assertOk();
    }
}
