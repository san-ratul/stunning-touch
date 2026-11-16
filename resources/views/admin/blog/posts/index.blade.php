@extends('layouts.admin')

@section('title', 'Blog Posts')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex flex-1 flex-wrap gap-2">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search posts…" class="input max-w-xs">
            <select name="status" class="input max-w-[160px]">
                <option value="">All statuses</option>
                @foreach(['draft', 'scheduled', 'published', 'archived'] as $status)
                    <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-secondary">Filter</button>
        </form>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.blog.generate.create') }}" class="btn-secondary">✨ Generate New Blog</a>
            <a href="{{ route('admin.blog.posts.create') }}" class="btn-accent">+ New Post</a>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[850px] text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Title</th>
                        <th class="px-4 py-3 font-semibold">Category</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 font-semibold">Source</th>
                        <th class="px-4 py-3 font-semibold">Published</th>
                        <th class="px-4 py-3 font-semibold">Views</th>
                        <th class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($posts as $post)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-3">
                                <p class="font-semibold text-slate-900">{{ \Illuminate\Support\Str::limit($post->title, 60) }}</p>
                                <p class="text-xs text-slate-500">/blog/{{ $post->slug }} · {{ $post->reading_time }} min</p>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $post->blogCategory?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="{{ match ($post->status) { 'published' => 'badge-green', 'scheduled' => 'badge-amber', 'archived' => 'badge-slate', default => 'badge-slate' } }}">
                                    {{ ucfirst($post->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3"><span class="{{ $post->source === 'ai' ? 'badge-amber' : 'badge-slate' }}">{{ strtoupper($post->source) }}</span></td>
                            <td class="px-4 py-3 text-slate-500">{{ $post->published_at?->format('M j, Y') ?? '—' }}</td>
                            <td class="px-4 py-3 tabular-nums text-slate-600">{{ number_format($post->views) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    @if($post->status === 'published')
                                        <a href="{{ route('blog.show', $post->slug) }}" target="_blank" class="btn-ghost px-2 py-1 text-xs">View</a>
                                    @endif
                                    <a href="{{ route('admin.blog.posts.edit', $post) }}" class="btn-secondary px-2 py-1 text-xs">Edit</a>
                                    @if($post->status !== 'published')
                                        <form method="POST" action="{{ route('admin.blog-posts.publish', $post) }}">
                                            @csrf
                                            <button type="submit" class="btn-ghost px-2 py-1 text-xs text-emerald-600">Publish</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.blog-posts.unpublish', $post) }}">
                                            @csrf
                                            <button type="submit" class="btn-ghost px-2 py-1 text-xs">Unpublish</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('admin.blog-posts.archive', $post) }}">
                                        @csrf
                                        <button type="submit" class="btn-ghost px-2 py-1 text-xs">Archive</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.blog.posts.destroy', $post) }}" onsubmit="return confirm('Delete this post permanently?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn-danger px-2 py-1 text-xs">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">No posts yet. Create your first article.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 px-4 py-3">{{ $posts->links() }}</div>
    </div>
@endsection
