@extends('layouts.public')

@section('content')
    @if($ads['top'])
        <div class="container-app pt-6">{!! $ads['top'] !!}</div>
    @endif

    {{-- Hero --}}
    <section class="relative overflow-hidden">
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div class="hero-blob absolute -top-24 left-1/2 h-[500px] w-[900px] -translate-x-1/2 rounded-full bg-gradient-to-br from-brand-100 via-brand-50 to-accent-100 opacity-70 blur-3xl"></div>
        </div>

        <div class="container-app pb-12 pt-16 text-center sm:pt-24">
            <span class="badge-slate mb-5 inline-flex">
                <x-icon name="sparkle" class="h-3.5 w-3.5 text-accent-500"/>
                100% free · No sign-up · Private by design
            </span>
            <h1 class="mx-auto max-w-3xl text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl lg:text-6xl">
                Your Everyday Tools, <span class="gradient-text">All in One Place.</span>
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-lg leading-relaxed text-slate-600">
                Compress images, merge PDFs, count words, format JSON, generate QR codes and more —
                fast, free and processed right in your browser. Your files never leave your device.
            </p>
            <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                <a href="{{ route('tools.index') }}" class="btn-accent btn-lg">
                    Browse All Tools
                    <x-icon name="chevron-right" class="h-5 w-5"/>
                </a>
                <a href="{{ route('blog.index') }}" class="btn-secondary btn-lg">Read the Blog</a>
            </div>
        </div>
    </section>

    @if($ads['content'])
        <div class="container-app">{!! $ads['content'] !!}</div>
    @endif

    {{-- Featured tools --}}
    @if($featuredTools->isNotEmpty())
        <section class="container-app mt-8" aria-labelledby="featured-heading">
            <div class="mb-6 flex items-center justify-between">
                <h2 id="featured-heading" class="text-2xl font-bold text-slate-900">Featured Tools</h2>
                <a href="{{ route('tools.index') }}" class="text-sm font-medium text-brand-600 hover:text-brand-800">View all →</a>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach($featuredTools->take(8) as $tool)
                    <x-tool-card :tool="$tool"/>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Categories with tools --}}
    <section class="container-app mt-14" aria-labelledby="categories-heading">
        <h2 id="categories-heading" class="mb-6 text-2xl font-bold text-slate-900">Explore by Category</h2>

        <div class="space-y-10">
            @foreach($categories as $category)
                <div>
                    <div class="mb-4 flex items-center gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600/10 text-brand-600">
                            <x-icon name="{{ $category->icon ?: 'tools' }}" class="h-5 w-5"/>
                        </span>
                        <div>
                            <h3 class="font-semibold text-slate-900">
                                <a href="/{{ $category->slug }}" class="hover:text-brand-600">{{ $category->name }}</a>
                            </h3>
                            @if($category->short_description)
                                <p class="text-sm text-slate-500">{{ $category->short_description }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach($category->activeTools->take(4) as $tool)
                            <x-tool-card :tool="$tool"/>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Why choose us --}}
    <section class="container-app mt-16" aria-labelledby="why-heading">
        <h2 id="why-heading" class="mb-6 text-2xl font-bold text-slate-900">Why {{ \App\Support\SeoResolver::siteName() }}?</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach([
                ['shield', 'Private by design', 'Image, PDF and text processing happens entirely in your browser. Your files are never uploaded to any server.'],
                ['globe', 'Free for everyone', 'Every tool is free with no sign-up walls, no watermarks and no usage limits for normal use.'],
                ['clock', 'Fast and lightweight', 'Optimized pages and minimal JavaScript keep tools quick even on slow mobile connections.'],
                ['sparkles', 'Constantly growing', 'New tools are added regularly based on what people actually search for and need.'],
            ] as [$icon, $title, $text])
                <div class="card p-6">
                    <span class="mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-brand-600 to-accent-500 text-white shadow-sm">
                        <x-icon name="{{ $icon }}" class="h-5 w-5"/>
                    </span>
                    <h3 class="font-semibold text-slate-900">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </section>

    @if($ads['bottom'])
        <div class="container-app mt-12">{!! $ads['bottom'] !!}</div>
    @endif
@endsection
