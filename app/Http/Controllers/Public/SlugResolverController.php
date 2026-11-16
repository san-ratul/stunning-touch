<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\StaticPage;
use App\Models\Tool;
use App\Support\AdRenderer;
use App\Support\SeoResolver;
use App\Support\ToolRegistry;
use Illuminate\Http\Request;

/**
 * Root-level slugs resolve in a fixed, safe order:
 * static page -> tool category -> tool. Unknown slugs render 404.
 */
class SlugResolverController extends Controller
{
    public function __invoke(Request $request, string $slug)
    {
        $static = StaticPage::query()->where('slug', $slug)->where('is_active', true)->first();

        if ($static && ! in_array($slug, ['about', 'contact', 'privacy-policy', 'terms', 'disclaimer'], true)) {
            return $this->renderStaticPage($static);
        }

        $category = Category::query()->where('slug', $slug)->where('is_active', true)->first();

        if ($category) {
            return $this->renderCategory($request, $category);
        }

        $tool = Tool::query()->where('slug', $slug)->active()->with('category')->first();

        if ($tool && ToolRegistry::exists($tool->component_key)) {
            return $this->renderTool($request, $tool);
        }

        abort(404);
    }

    protected function renderStaticPage($page)
    {
        $meta = SeoResolver::forModel($page);

        return view('pages.static', [
            'page' => $page,
            'seo' => SeoResolver::normalize($meta, $page->title, null),
            'pageType' => 'static_page',
        ]);
    }

    protected function renderCategory(Request $request, Category $category)
    {
        $tools = $category->activeTools()->ordered()->get();

        $meta = SeoResolver::forModel($category);

        return view('tools.category', [
            'category' => $category,
            'tools' => $tools,
            'seo' => SeoResolver::normalize(
                $meta,
                $category->name.' — Free Online Tools',
                $category->short_description ?: ($category->description ?: "Free {$category->name} you can use right in your browser."),
            ),
            'pageType' => 'category',
            'breadcrumbs' => [
                ['label' => 'Tools', 'url' => route('tools.index')],
                ['label' => $category->name],
            ],
            'ad' => AdRenderer::render('category_top', 'category', null, $request->attributes->get('device', 'all')),
        ]);
    }

    protected function renderTool(Request $request, Tool $tool)
    {
        $registryConfig = ToolRegistry::config($tool->component_key);
        $meta = SeoResolver::forModel($tool);

        $relatedTools = Tool::query()
            ->active()
            ->where('category_id', $tool->category_id)
            ->whereKeyNot($tool->id)
            ->ordered()
            ->limit(4)
            ->get();

        return view($registryConfig['view'], [
            'tool' => $tool,
            'category' => $tool->category,
            'relatedTools' => $relatedTools,
            'seo' => SeoResolver::normalize(
                $meta,
                $tool->name.' — Free Online Tool',
                $tool->short_description ?: ($tool->description ?: "Use {$tool->name} free online."),
            ),
            'pageType' => 'tool',
            'registryConfig' => $registryConfig,
            'breadcrumbs' => [
                ['label' => $tool->category?->name ?? 'Tools', 'url' => $tool->category ? '/'.$tool->category->slug : route('tools.index')],
                ['label' => $tool->name],
            ],
            'ads' => [
                'before' => AdRenderer::render('tool_before_content', 'tool', $tool->component_key, $request->attributes->get('device', 'all')),
                'after' => AdRenderer::render('tool_after_content', 'tool', $tool->component_key, $request->attributes->get('device', 'all')),
                'sidebar' => AdRenderer::render('tool_sidebar', 'tool', $tool->component_key, $request->attributes->get('device', 'all')),
                'bottom' => AdRenderer::render('tool_bottom', 'tool', $tool->component_key, $request->attributes->get('device', 'all')),
            ],
        ]);
    }
}
