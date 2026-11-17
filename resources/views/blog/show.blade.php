@extends('layouts.public')

@section('content')
    @php
        /** @var \App\Models\BlogPost $post */
        $shareUrl = url(route('blog.show', $post->slug, false));
    @endphp

    <article class="container-app py-10">
        @include('partials.breadcrumbs', ['breadcrumbs' => $breadcrumbs ?? []])

        <div class="grid gap-10 lg:grid-cols-[1fr_300px]">
            <div class="min-w-0">
                <header>
                    @if($post->blogCategory)
                        <a href="{{ route('blog.category', $post->blogCategory->slug) }}" class="badge-green">{{ $post->blogCategory->name }}</a>
                    @endif
                    <h1 class="mt-3 text-3xl font-extrabold leading-tight tracking-tight text-slate-900 sm:text-4xl">{{ $post->title }}</h1>

                    <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-500">
                        @if($post->author)
                            <span class="flex items-center gap-2">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-600/10 text-sm font-bold text-brand-700">
                                    {{ strtoupper(substr($post->author->name, 0, 1)) }}
                                </span>
                                <span class="font-medium text-slate-700">{{ $post->author->name }}</span>
                            </span>
                        @endif
                        <span aria-hidden="true">·</span>
                        <time datetime="{{ $post->published_at->toIso8601String() }}">{{ $post->published_at->format('F j, Y') }}</time>
                        <span aria-hidden="true">·</span>
                        <span class="inline-flex items-center gap-1"><x-icon name="clock" class="h-4 w-4"/> {{ $post->reading_time }} min read</span>
                    </div>
                </header>

                @if($post->featured_image)
                    <img src="{{ asset('storage/'.$post->featured_image) }}"
                         alt="{{ $post->featured_image_alt ?: $post->title }}"
                         class="mt-6 aspect-video w-full rounded-2xl border border-slate-200 object-cover"
                         loading="eager" fetchpriority="high">
                @endif

                {{-- Social sharing --}}
                <div class="mt-6 flex flex-wrap items-center gap-2 border-y border-slate-100 py-4">
                    <span class="text-sm font-medium text-slate-600">Share:</span>
                    <a href="https://twitter.com/intent/tweet?url={{ urlencode($shareUrl) }}&text={{ urlencode($post->title) }}"
                       target="_blank" rel="noopener nofollow" class="btn-secondary px-3 py-1.5 text-xs">𝕏 X</a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($shareUrl) }}"
                       target="_blank" rel="noopener nofollow" class="btn-secondary px-3 py-1.5 text-xs">Facebook</a>
                    <a href="https://www.linkedin.com/sharing/share-offsite/?url={{ urlencode($shareUrl) }}"
                       target="_blank" rel="noopener nofollow" class="btn-secondary px-3 py-1.5 text-xs">LinkedIn</a>
                    <a href="mailto:?subject={{ urlencode($post->title) }}&body={{ urlencode($shareUrl) }}"
                       class="btn-secondary px-3 py-1.5 text-xs">Email</a>
                </div>

                {{-- Article body --}}
                <div class="prose-article mt-8 max-w-3xl">{!! $post->content !!}</div>

                @if($post->tags->isNotEmpty())
                    <div class="mt-8 flex flex-wrap gap-2">
                        @foreach($post->tags as $tag)
                            <span class="badge-slate">#{{ $tag->name }}</span>
                        @endforeach
                    </div>
                @endif

                @if($relatedTools->isNotEmpty())
                    <section class="mt-10 rounded-2xl border border-brand-200 bg-brand-50/50 p-6" aria-labelledby="tools-heading">
                        <h2 id="tools-heading" class="text-lg font-bold text-slate-900">Try the tools mentioned in this article</h2>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach($relatedTools as $tool)
                                <a href="{{ $tool->url() }}" class="card card-hover flex items-center gap-3 p-4">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-600/10 text-brand-600">
                                        <x-icon name="{{ $tool->icon ?: 'tools' }}" class="h-4 w-4"/>
                                    </span>
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900">{{ $tool->name }}</p>
                                        <p class="text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($tool->short_description, 70) }}</p>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if($relatedPosts->isNotEmpty())
                    <section class="mt-12" aria-labelledby="related-heading">
                        <h2 id="related-heading" class="text-xl font-bold text-slate-900">Related Articles</h2>
                        <div class="mt-4 grid gap-5 sm:grid-cols-3">
                            @foreach($relatedPosts as $related)
                                @include('blog._post-card', ['post' => $related])
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            <aside class="space-y-6">
                <div class="sticky top-24 space-y-6">
                    <div class="card bg-gradient-to-br from-brand-600/5 to-accent-500/5 p-5">
                        <p class="text-sm font-semibold text-slate-900">Free online tools</p>
                        <p class="mt-1.5 text-sm text-slate-600">Put this article's advice into practice with our browser-based tools.</p>
                        <a href="{{ route('tools.index') }}" class="btn-primary mt-3 w-full">Explore tools</a>
                    </div>
                </div>
            </aside>
        </div>
    </article>

    {{-- Article structured data --}}
    @php
        $articleSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $post->title,
            'description' => $post->meta_description ?: $post->excerpt,
            'url' => $shareUrl,
            'datePublished' => $post->published_at->toIso8601String(),
            'dateModified' => $post->updated_at->toIso8601String(),
            'author' => ['@type' => 'Person', 'name' => $post->author?->name ?? \App\Support\SeoResolver::siteName().' Editorial Team'],
            'publisher' => ['@type' => 'Organization', 'name' => \App\Support\SeoResolver::siteName()],
        ];
        if ($post->featured_image) {
            $articleSchema['image'] = asset('storage/'.$post->featured_image);
        }
    @endphp
    <script type="application/ld+json">
        {!! json_encode($articleSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
@endsection
