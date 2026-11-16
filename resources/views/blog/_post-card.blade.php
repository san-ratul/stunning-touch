@php /** @var \App\Models\BlogPost $post */ @endphp

<article class="card card-hover flex flex-col overflow-hidden">
    @if($post->featured_image)
        <a href="{{ route('blog.show', $post->slug) }}">
            <img src="{{ asset('storage/'.$post->featured_image) }}" alt="{{ $post->featured_image_alt ?: $post->title }}" class="h-44 w-full object-cover" loading="lazy">
        </a>
    @endif
    <div class="flex flex-1 flex-col gap-2.5 p-5">
        @if($post->blogCategory)
            <a href="{{ route('blog.category', $post->blogCategory->slug) }}" class="badge-slate w-fit hover:text-brand-700">{{ $post->blogCategory->name }}</a>
        @endif
        <h3 class="text-lg font-bold leading-snug text-slate-900">
            <a href="{{ route('blog.show', $post->slug) }}" class="hover:text-brand-700">{{ $post->title }}</a>
        </h3>
        <p class="text-sm leading-relaxed text-slate-600">{{ \Illuminate\Support\Str::limit($post->excerpt, 140) }}</p>
        <p class="mt-auto pt-2 text-xs text-slate-500">
            {{ $post->author?->name ?? 'Editorial Team' }} · {{ $post->published_at->format('M j, Y') }} · {{ $post->reading_time }} min read
        </p>
    </div>
</article>
