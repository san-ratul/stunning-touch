<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BlogPostController extends Controller
{
    public function index(Request $request): View
    {
        $posts = BlogPost::query()
            ->with(['blogCategory', 'author'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->q.'%'))
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.blog.posts.index', ['posts' => $posts]);
    }

    public function create(): View
    {
        return view('admin.blog.posts.form', $this->formData(new BlogPost));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['user_id'] = auth()->id();
        $post = BlogPost::create($data);
        $this->syncTags($post, $request);
        AuditLog::record('post.created', $post, 'Created blog post: '.$post->title);

        return redirect()->route('admin.blog.posts.index')->with('success', 'Post created.');
    }

    public function edit(BlogPost $post): View
    {
        $post->load('tags');

        return view('admin.blog.posts.form', $this->formData($post));
    }

    public function update(Request $request, BlogPost $post): RedirectResponse
    {
        $post->update($this->validated($request, $post));
        $this->syncTags($post, $request);
        AuditLog::record('post.updated', $post, 'Updated blog post: '.$post->title);

        return redirect()->route('admin.blog.posts.index')->with('success', 'Post updated.');
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        AuditLog::record('post.deleted', $post, 'Deleted blog post: '.$post->title);
        $post->delete();

        return redirect()->route('admin.blog.posts.index')->with('success', 'Post deleted.');
    }

    public function publish(BlogPost $post): RedirectResponse
    {
        $post->update(['status' => 'published', 'published_at' => $post->published_at ?? now(), 'scheduled_at' => null]);
        AuditLog::record('post.published', $post, 'Published: '.$post->title);

        return back()->with('success', 'Post published.');
    }

    public function unpublish(BlogPost $post): RedirectResponse
    {
        $post->update(['status' => 'draft']);
        AuditLog::record('post.unpublished', $post, 'Unpublished: '.$post->title);

        return back()->with('success', 'Post unpublished.');
    }

    public function archive(BlogPost $post): RedirectResponse
    {
        $post->update(['status' => 'archived']);
        AuditLog::record('post.archived', $post, 'Archived: '.$post->title);

        return back()->with('success', 'Post archived.');
    }

    protected function formData(BlogPost $post): array
    {
        return [
            'post' => $post,
            'categories' => BlogCategory::query()->orderBy('name')->get(),
            'tags' => BlogTag::query()->orderBy('name')->get(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'mediaLibrary' => Media::query()->latest()->limit(50)->get(),
        ];
    }

    protected function syncTags(BlogPost $post, Request $request): void
    {
        $tagIds = collect((array) $request->input('tags', []))
            ->map(fn ($tag) => is_numeric($tag) ? (int) $tag : BlogTag::firstOrCreate(['slug' => Str::slug($tag)], ['name' => $tag])->id)
            ->filter()
            ->unique()
            ->all();

        $post->tags()->sync($tagIds);
    }

    protected function validated(Request $request, ?BlogPost $post = null): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:blog_posts,slug'.($post ? ','.$post->id : '')],
            'blog_category_id' => ['nullable', 'exists:blog_categories,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'excerpt' => ['nullable', 'string', 'max:1000'],
            'content' => ['nullable', 'string', 'max:200000'],
            'featured_image' => ['nullable', 'string', 'max:500'],
            'featured_image_alt' => ['nullable', 'string', 'max:500'],
            'focus_keyword' => ['nullable', 'string', 'max:255'],
            'secondary_keywords' => ['nullable', 'string', 'max:500'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'string', 'max:500'],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string', 'max:500'],
            'og_image' => ['nullable', 'string', 'max:500'],
            'twitter_title' => ['nullable', 'string', 'max:255'],
            'twitter_description' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'in:draft,scheduled,published,archived'],
            'scheduled_at' => ['nullable', 'date', 'required_if:status,scheduled'],
            'published_at' => ['nullable', 'date'],
        ]);

        $validated['reading_time'] = BlogPost::readingTimeFor((string) ($validated['content'] ?? ''));

        if (($validated['status'] ?? '') === 'published' && blank($validated['published_at'] ?? null) && $post?->published_at === null) {
            $validated['published_at'] = now();
        }

        if ($post && $post->status === 'published' && ($validated['status'] ?? 'published') === 'published') {
            $validated['published_at'] = $post->published_at;
        }

        return $validated;
    }
}
