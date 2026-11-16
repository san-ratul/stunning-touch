<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\StaticPage;
use App\Models\Tool;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $urls = collect();

        $urls->push(['loc' => url('/'), 'priority' => '1.0', 'changefreq' => 'daily']);

        StaticPage::query()->where('is_active', true)->get()
            ->each(fn ($p) => $urls->push(['loc' => url('/'.$p->slug), 'priority' => '0.4', 'changefreq' => 'monthly', 'lastmod' => $p->updated_at]));

        Category::query()->active()->ordered()->get()
            ->each(fn ($c) => $urls->push(['loc' => url('/'.$c->slug), 'priority' => '0.8', 'changefreq' => 'weekly', 'lastmod' => $c->updated_at]));

        Tool::query()->active()->with('category')->get()
            ->each(fn ($t) => $urls->push(['loc' => url('/'.$t->slug), 'priority' => '0.9', 'changefreq' => 'weekly', 'lastmod' => $t->updated_at]));

        BlogCategory::query()->where('is_active', true)->get()
            ->each(fn ($c) => $urls->push(['loc' => route('blog.category', $c->slug), 'priority' => '0.5', 'changefreq' => 'weekly', 'lastmod' => $c->updated_at]));

        BlogPost::query()->published()->get()
            ->each(fn ($p) => $urls->push(['loc' => route('blog.show', $p->slug), 'priority' => '0.7', 'changefreq' => 'monthly', 'lastmod' => $p->updated_at ?: $p->published_at]));

        $xml = view('seo.sitemap', ['urls' => $urls])->render();

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /admin/*',
            'Disallow: /api/*',
            'Disallow: /run-scheduler/*',
            '',
            'Sitemap: '.url('/sitemap.xml'),
            '',
        ];

        return response(implode("\n", $lines), 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
