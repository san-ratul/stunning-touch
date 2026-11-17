<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Tool;
use App\Support\SeoResolver;
use Illuminate\Http\Request;

class ToolsController extends Controller
{
    public function index()
    {
        $categories = Category::query()
            ->active()
            ->ordered()
            ->with(['activeTools' => fn ($q) => $q->ordered()])
            ->get();

        $meta = SeoResolver::normalize(
            null,
            'All Online Tools',
            'Browse every free online tool on '.SeoResolver::siteName().': image tools, PDF tools, text utilities, developer tools and generators. Fast, private, browser-based.'
        );

        return view('tools.index', [
            'categories' => $categories,
            'seo' => $meta,
            'pageType' => 'tools_index',
        ]);
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $tools = Tool::query()
            ->active()
            ->with('category')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('short_description', 'like', "%{$q}%")))
            ->ordered()
            ->limit(20)
            ->get();

        return response()->json([
            'results' => $tools->map(fn (Tool $tool) => [
                'name' => $tool->name,
                'url' => $tool->url(),
                'description' => $tool->short_description,
                'category' => $tool->category?->name,
            ]),
        ]);
    }
}
