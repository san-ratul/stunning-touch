<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlogTag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BlogTagController extends Controller
{
    public function index(): View
    {
        return view('admin.blog.tags.index', [
            'tags' => BlogTag::query()->withCount('posts')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:blog_tags,slug'],
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);

        $tag = BlogTag::create($validated);
        AuditLog::record('blog_tag.created', $tag, 'Created blog tag: '.$tag->name);

        return redirect()->route('admin.blog.tags.index')->with('success', 'Tag created.');
    }

    public function update(Request $request, BlogTag $tag): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:blog_tags,slug,'.$tag->id],
        ]);

        $tag->update($validated);
        AuditLog::record('blog_tag.updated', $tag, 'Updated blog tag: '.$tag->name);

        return redirect()->route('admin.blog.tags.index')->with('success', 'Tag updated.');
    }

    public function destroy(BlogTag $tag): RedirectResponse
    {
        AuditLog::record('blog_tag.deleted', $tag, 'Deleted blog tag: '.$tag->name);
        $tag->delete();

        return redirect()->route('admin.blog.tags.index')->with('success', 'Tag deleted.');
    }
}
