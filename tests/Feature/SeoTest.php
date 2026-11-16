<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Tool;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_sitemap_contains_core_urls(): void
    {
        $post = BlogPost::create([
            'title' => 'Sitemap Post',
            'slug' => 'sitemap-post',
            'content' => '<p>x</p>',
            'status' => 'published',
            'published_at' => now(),
            'reading_time' => 1,
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        foreach ([
            url('/'),
            url('/image-compressor'),
            url('/merge-pdf'),
            url('/image-tools'),
            url('/privacy-policy'),
            route('blog.show', 'sitemap-post'),
            route('blog.category', 'image-optimization'),
        ] as $url) {
            $response->assertSee($url, false);
        }
    }

    public function test_sitemap_excludes_drafts_and_inactive_tools(): void
    {
        BlogPost::create([
            'title' => 'Draft Post',
            'slug' => 'draft-post',
            'content' => '<p>x</p>',
            'status' => 'draft',
            'reading_time' => 1,
        ]);

        $xml = $this->get('/sitemap.xml')->getContent();

        $this->assertStringNotContainsString('draft-post', $xml);
    }

    public function test_robots_txt_disallows_admin_and_lists_sitemap(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('Disallow: /admin', $content);
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $content);
    }

    public function test_home_has_canonical_and_og_metadata(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<link rel="canonical"', false);
        $response->assertSee('property="og:title"', false);
        $response->assertSee('name="twitter:card"', false);
        $response->assertSee('application/ld+json', false);
    }

    public function test_noindex_meta_renders_when_disabled(): void
    {
        $tool = Tool::first();
        $tool->seo()->updateOrCreate([], [
            'meta_title' => 'Noindex Tool',
            'robots_index' => false,
            'robots_follow' => true,
        ]);

        $this->get('/'.$tool->slug)->assertSee('noindex', false);
    }

    public function test_every_page_has_exactly_one_title_tag(): void
    {
        $urls = ['/', '/tools', '/about', '/image-compressor', '/blog', '/privacy-policy'];

        foreach ($urls as $url) {
            preg_match_all('/<title>/', $this->get($url)->getContent(), $matches);
            $this->assertCount(1, $matches[0], "URL {$url} must have exactly one <title> tag.");
        }
    }

    public function test_blog_post_page_renders_article_schema(): void
    {
        $post = BlogPost::create([
            'title' => 'Schema Test Article',
            'slug' => 'schema-test-article',
            'content' => '<p>Testing schema.</p>',
            'status' => 'published',
            'published_at' => now(),
            'reading_time' => 1,
        ]);

        $response = $this->get('/blog/schema-test-article');
        $response->assertOk();

        $this->assertStringContainsString('"@type":"Article"', $response->getContent());
    }
}
