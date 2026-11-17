@extends('layouts.public')

@section('content')
    <div class="container-app py-10">
        @include('partials.breadcrumbs', ['breadcrumbs' => [['label' => 'Blog']]])

        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">The {{ \App\Support\SeoResolver::siteName() }} Blog</h1>
        <p class="mt-3 max-w-2xl text-slate-600">Guides, tips and tutorials about online tools, productivity, SEO and web development.</p>

        @if($featuredPost)
            <a href="{{ route('blog.show', $featuredPost->slug) }}" class="card card-hover mt-8 block overflow-hidden">
                <div class="grid md:grid-cols-2">
                    @if($featuredPost->featured_image)
                        <img src="{{ asset('storage/'.$featuredPost->featured_image) }}" alt="{{ $featuredPost->featured_image_alt ?: $featuredPost->title }}" class="h-64 w-full object-cover md:h-full" loading="lazy">
                    @else
                        <div class="flex h-48 items-center justify-center bg-gradient-to-br from-brand-600/10 to-accent-500/10 md:h-full">
                            <x-icon name="blog" class="h-12 w-12 text-brand-400"/>
                        </div>
                    @endif
                    <div class="flex flex-col justify-center gap-3 p-6 sm:p-8">
                        <span class="badge-green w-fit">Featured</span>
                        <h2 class="text-2xl font-bold text-slate-900">{{ $featuredPost->title }}</h2>
                        <p class="text-slate-600">{{ \Illuminate\Support\Str::limit($featuredPost->excerpt, 180) }}</p>
                        <p class="text-sm text-slate-500">{{ $featuredPost->author?->name ?? 'Editorial Team' }} · {{ $featuredPost->published_at->format('M j, Y') }} · {{ $featuredPost->reading_time }} min read</p>
                    </div>
                </div>
            </a>
        @endif

        <div class="mt-10 grid gap-10 lg:grid-cols-[1fr_300px]">
            <div>
                @if($posts->isEmpty())
                    <div class="card p-10 text-center">
                        <p class="text-slate-500">No articles published yet. Check back soon!</p>
                    </div>
                @else
                    <div class="grid gap-5 sm:grid-cols-2">
                        @foreach($posts as $post)
                            @include('blog._post-card', ['post' => $post])
                        @endforeach
                    </div>
                    <div class="mt-8">{{ $posts->links() }}</div>
                @endif
            </div>

            <aside class="space-y-6">
                <div class="card p-5">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-900">Categories</h2>
                    <ul class="mt-3 space-y-2">
                        @foreach($blogCategories as $cat)
                            <li>
                                <a href="{{ route('blog.category', $cat->slug) }}" class="text-sm text-slate-600 hover:text-brand-600">{{ $cat->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="card bg-gradient-to-br from-brand-600/5 to-accent-500/5 p-5">
                    <p class="text-sm font-semibold text-slate-900">Try our free tools</p>
                    <p class="mt-1.5 text-sm text-slate-600">12 fast, browser-based utilities. No sign-up needed.</p>
                    <a href="{{ route('tools.index') }}" class="btn-primary mt-3 w-full">Browse tools</a>
                </div>
            </aside>
        </div>
    </div>
@endsection
