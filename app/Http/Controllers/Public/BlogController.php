<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Support\AdRenderer;
use App\Support\SeoResolver;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    public function index(Request $request)
    {
        $posts = BlogPost::query()
            ->published()
            ->with(['blogCategory', 'author'])
            ->latest('published_at')
            ->paginate(9)
            ->withQueryString();

        $featured = BlogPost::query()->published()->with('author')->latest('published_at')->first();
        $categories = BlogCategory::query()->where('is_active', true)->orderBy('name')->get();

        return view('blog.index', [
            'posts' => $posts,
            'featuredPost' => $featured,
            'blogCategories' => $categories,
            'seo' => SeoResolver::normalize(null, 'Blog', 'Guides, tips and tutorials about online tools, productivity, SEO and web development from '.SeoResolver::siteName().'.'),
            'pageType' => 'blog_index',
            'ads' => [
                'top' => AdRenderer::render('blog_content', 'blog_index', null, $request->attributes->get('device', 'all')),
                'sidebar' => AdRenderer::render('blog_sidebar', 'blog_index', null, $request->attributes->get('device', 'all')),
            ],
        ]);
    }

    public function category(Request $request, string $slug)
    {
        $category = BlogCategory::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        $posts = $category->posts()
            ->published()
            ->with(['author'])
            ->latest('published_at')
            ->paginate(9)
            ->withQueryString();

        $categories = BlogCategory::query()->where('is_active', true)->orderBy('name')->get();

        return view('blog.category', [
            'blogCategory' => $category,
            'posts' => $posts,
            'blogCategories' => $categories,
            'seo' => SeoResolver::normalize(SeoResolver::forModel($category), $category->name.' — Blog', $category->description),
            'pageType' => 'blog_category',
            'breadcrumbs' => [
                ['label' => 'Blog', 'url' => route('blog.index')],
                ['label' => $category->name],
            ],
            'ads' => [
                'sidebar' => AdRenderer::render('blog_sidebar', 'blog_category', null, $request->attributes->get('device', 'all')),
            ],
        ]);
    }

    public function show(Request $request, string $slug)
    {
        $post = BlogPost::query()
            ->where('slug', $slug)
            ->published()
            ->with(['blogCategory', 'author', 'tags'])
            ->firstOrFail();

        $post->increment('views');

        $related = BlogPost::query()
            ->published()
            ->whereKeyNot($post->id)
            ->where(fn ($q) => $q
                ->where('blog_category_id', $post->blog_category_id)
                ->orWhereRaw('1=0'))
            ->latest('published_at')
            ->limit(3)
            ->get();

        if ($related->count() < 3) {
            $related = BlogPost::query()
                ->published()
                ->whereKeyNot($post->id)
                ->latest('published_at')
                ->limit(3)
                ->get();
        }

        $relatedTools = collect();

        $focusKeyword = $post->focus_keyword;

        if ($focusKeyword) {
            $relatedTools = BlogPost::findToolsForKeywords(array_filter(array_map('trim', explode(',', $focusKeyword.','.$post->secondary_keywords))));
        }

        return view('blog.show', [
            'post' => $post,
            'relatedPosts' => $related,
            'relatedTools' => $relatedTools,
            'seo' => SeoResolver::normalize(
                [
                    'title' => $post->meta_title,
                    'description' => $post->meta_description ?: $post->excerpt,
                    'canonical' => $post->canonical_url,
                    'og_title' => $post->og_title,
                    'og_description' => $post->og_description,
                    'og_image' => $post->og_image ?: $post->featured_image,
                    'twitter_title' => $post->twitter_title,
                    'twitter_description' => $post->twitter_description,
                    'twitter_image' => $post->featured_image,
                    'robots_index' => $post->robots_index,
                    'robots_follow' => $post->robots_follow,
                    'focus_keyword' => $post->focus_keyword,
                    'secondary_keywords' => $post->secondary_keywords,
                    'schema_type' => 'Article',
                ],
                $post->title,
                $post->excerpt
            ),
            'pageType' => 'blog_post',
            'breadcrumbs' => [
                ['label' => 'Blog', 'url' => route('blog.index')],
                ['label' => $post->blogCategory?->name ?? 'Articles', 'url' => $post->blogCategory ? route('blog.category', $post->blogCategory->slug) : null],
                ['label' => $post->title],
            ],
            'ads' => [
                'sidebar' => AdRenderer::render('blog_sidebar', 'blog_post', null, $request->attributes->get('device', 'all')),
                'footer' => AdRenderer::render('blog_footer', 'blog_post', null, $request->attributes->get('device', 'all')),
            ],
        ]);
    }
}
