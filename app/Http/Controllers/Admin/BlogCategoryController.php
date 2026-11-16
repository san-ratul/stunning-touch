<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlogCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BlogCategoryController extends Controller
{
    public function index(): View
    {
        return view('admin.blog.categories.index', [
            'categories' => BlogCategory::query()->withCount('posts')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.blog.categories.form', ['category' => new BlogCategory]);
    }

    public function store(Request $request): RedirectResponse
    {
        $category = BlogCategory::create($this->validated($request));
        AuditLog::record('blog_category.created', $category, 'Created blog category: '.$category->name);

        return redirect()->route('admin.blog.categories.index')->with('success', 'Blog category created.');
    }

    public function edit(BlogCategory $category): View
    {
        return view('admin.blog.categories.form', ['category' => $category]);
    }

    public function update(Request $request, BlogCategory $category): RedirectResponse
    {
        $category->update($this->validated($request, $category));
        AuditLog::record('blog_category.updated', $category, 'Updated blog category: '.$category->name);

        return redirect()->route('admin.blog.categories.index')->with('success', 'Blog category updated.');
    }

    public function destroy(BlogCategory $category): RedirectResponse
    {
        AuditLog::record('blog_category.deleted', $category, 'Deleted blog category: '.$category->name);
        $category->delete();

        return redirect()->route('admin.blog.categories.index')->with('success', 'Blog category deleted.');
    }

    protected function validated(Request $request, ?BlogCategory $category = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:blog_categories,slug'.($category ? ','.$category->id : '')],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);

        return $validated;
    }
}
