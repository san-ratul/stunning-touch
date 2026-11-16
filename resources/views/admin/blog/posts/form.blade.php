@extends('layouts.admin')

@section('title', $post->exists ? 'Edit Post' : 'New Post')

@section('content')
    <form method="POST"
          action="{{ $post->exists ? route('admin.blog.posts.update', $post) : route('admin.blog.posts.store') }}"
          class="grid gap-6 xl:grid-cols-3" x-data="{{ json_encode(['mediaPicker' => false]) }}">
        @csrf
        @if($post->exists) @method('PUT') @endif

        <div class="space-y-6 xl:col-span-2">
            <div class="card p-6">
                <div class="space-y-4">
                    <div>
                        <label class="label" for="title">Title *</label>
                        <input id="title" name="title" value="{{ old('title', $post->title) }}" required class="input" maxlength="255">
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label" for="slug">Slug *</label>
                            <input id="slug" name="slug" value="{{ old('slug', $post->slug) }}" required class="input" pattern="[a-z0-9]+(-[a-z0-9]+)*">
                        </div>
                        <div>
                            <label class="label" for="blog_category_id">Category</label>
                            <select id="blog_category_id" name="blog_category_id" class="input">
                                <option value="">— none —</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}" {{ old('blog_category_id', $post->blog_category_id) == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="label" for="excerpt">Excerpt (shown in listings)</label>
                        <textarea id="excerpt" name="excerpt" rows="2" maxlength="1000" class="input">{{ old('excerpt', $post->excerpt) }}</textarea>
                    </div>
                    <div>
                        <label class="label" for="content">Content (HTML)</label>
                        <textarea id="content" name="content" rows="22" class="input font-mono text-[13px] scroll-thin">{{ old('content', $post->content) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="card p-6">
                <h2 class="mb-4 font-bold text-slate-900">SEO Metadata</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="focus_keyword">Focus keyword</label>
                        <input id="focus_keyword" name="focus_keyword" value="{{ old('focus_keyword', $post->focus_keyword) }}" class="input">
                    </div>
                    <div>
                        <label class="label" for="secondary_keywords">Secondary keywords</label>
                        <input id="secondary_keywords" name="secondary_keywords" value="{{ old('secondary_keywords', $post->secondary_keywords) }}" class="input">
                    </div>
                    <div>
                        <label class="label" for="seo_title">SEO title</label>
                        <input id="seo_title" name="seo_title" value="{{ old('seo_title', $post->seo_title) }}" maxlength="255" class="input" data-counter="60">
                        <p class="counter-note mt-1 text-xs text-slate-500"></p>
                    </div>
                    <div>
                        <label class="label" for="meta_description">Meta description</label>
                        <textarea id="meta_description" name="meta_description" rows="2" maxlength="500" class="input" data-counter="155">{{ old('meta_description', $post->meta_description) }}</textarea>
                        <p class="counter-note mt-1 text-xs text-slate-500"></p>
                    </div>
                    <div>
                        <label class="label" for="canonical_url">Canonical URL</label>
                        <input id="canonical_url" name="canonical_url" type="url" value="{{ old('canonical_url', $post->canonical_url) }}" class="input">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                            <input type="hidden" name="robots_index" value="0">
                            <input type="checkbox" name="robots_index" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('robots_index', $post->exists ? $post->robots_index : true) ? 'checked' : '' }}>
                            Index
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                            <input type="hidden" name="robots_follow" value="0">
                            <input type="checkbox" name="robots_follow" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('robots_follow', $post->exists ? $post->robots_follow : true) ? 'checked' : '' }}>
                            Follow
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card p-6">
                <h2 class="mb-4 font-bold text-slate-900">Publishing</h2>
                <div class="space-y-4">
                    <div>
                        <label class="label" for="status">Status *</label>
                        <select id="status" name="status" required class="input">
                            @foreach(['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published', 'archived' => 'Archived'] as $value => $label)
                                <option value="{{ $value }}" {{ old('status', $post->status ?? 'draft') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="published_at">Published date</label>
                        <input id="published_at" type="datetime-local" name="published_at" value="{{ old('published_at', $post->published_at?->format('Y-m-d\TH:i')) }}" class="input">
                    </div>
                    <div>
                        <label class="label" for="scheduled_at">Scheduled for (used with status “Scheduled”)</label>
                        <input id="scheduled_at" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $post->scheduled_at?->format('Y-m-d\TH:i')) }}" class="input">
                    </div>
                    <div>
                        <label class="label" for="user_id">Author</label>
                        <select id="user_id" name="user_id" class="input">
                            <option value="">— {{ config('app.name') }} Editorial —</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}" {{ old('user_id', $post->user_id) == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">Tags (multi-select)</label>
                        <select name="tags[]" multiple class="input h-28">
                            @foreach($tags as $tag)
                                <option value="{{ $tag->id }}" {{ old('tags', $post->tags->pluck('id')->all()) ? (in_array($tag->id, old('tags', $post->tags->pluck('id')->all())) ? 'selected' : '') : '' }}>{{ $tag->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-500">Hold Ctrl/Cmd to select multiple; type new tags via the Tags module.</p>
                    </div>
                </div>
            </div>

            <div class="card p-6">
                <h2 class="mb-4 font-bold text-slate-900">Featured Image</h2>
                <div class="space-y-3">
                    <div>
                        <label class="label" for="featured_image">Image path (from Media Library or URL)</label>
                        <input id="featured_image" name="featured_image" value="{{ old('featured_image', $post->featured_image) }}" class="input" placeholder="/storage/media/photo.webp">
                    </div>
                    <div>
                        <label class="label" for="featured_image_alt">Image alt text</label>
                        <input id="featured_image_alt" name="featured_image_alt" value="{{ old('featured_image_alt', $post->featured_image_alt) }}" class="input">
                    </div>
                    @if($mediaLibrary->isNotEmpty())
                        <details class="rounded-xl border border-slate-200 p-3">
                            <summary class="cursor-pointer text-sm font-medium text-slate-700">Pick from Media Library</summary>
                            <div class="mt-3 grid max-h-56 grid-cols-3 gap-2 overflow-y-auto">
                                @foreach($mediaLibrary->where('mime_type', 'like', 'image/%') as $media)
                                    <button type="button"
                                            class="overflow-hidden rounded-lg border-2 hover:border-brand-500 {{ old('featured_image', $post->featured_image) === $media->file_path ? 'border-brand-500' : 'border-slate-200' }}"
                                            onclick="document.getElementById('featured_image').value = '{{ $media->file_path }}'; this.closest('details').open = false;">
                                        <img src="{{ $media->url() }}" alt="{{ $media->alt_text ?: $media->file_name }}" class="h-16 w-full object-cover" loading="lazy">
                                    </button>
                                @endforeach
                            </div>
                        </details>
                    @endif
                    @if($post->featured_image)
                        <img src="{{ asset('storage/'.$post->featured_image) }}" alt="Featured image preview" class="aspect-video w-full rounded-xl border border-slate-200 object-cover" loading="lazy">
                    @endif
                </div>
            </div>

            <button type="submit" class="btn-accent btn-lg w-full">{{ $post->exists ? 'Save Changes' : 'Create Post' }}</button>
        </div>
    </form>
@endsection

@push('bottom-scripts')
    <script>
        document.querySelectorAll('[data-counter]').forEach((input) => {
            const limit = Number(input.dataset.counter);
            const note = input.closest('div').querySelector('.counter-note');
            const update = () => {
                const len = input.value.length;
                note.textContent = `${len} / ${limit} characters ${len <= limit ? '✓' : '⚠ over limit'}`;
                note.className = `counter-note mt-1 text-xs ${len <= limit ? 'text-slate-500' : 'text-amber-600 font-medium'}`;
            };
            input.addEventListener('input', update);
            update();
        });
    </script>
@endpush
