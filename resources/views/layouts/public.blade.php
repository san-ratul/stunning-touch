<!DOCTYPE html>
<html lang="en" data-theme="system">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="monetag" content="95027b915dbd4431406d90a0a098ec34">

    <script>(function(){try{var q=new URLSearchParams(location.search).get('theme');var t=(q==='dark'||q==='light'||q==='system')?q:(localStorage.getItem('st-theme')||'system');var d=t==='dark'||(t==='system'&&window.matchMedia('(prefers-color-scheme: dark)').matches);var el=document.documentElement;el.classList.toggle('dark',d);el.dataset.theme=t;}catch(e){}})();</script>

    @include('partials.seo-head')

    @php
        $siteName = \App\Support\SeoResolver::siteName();
        $verification = \App\Models\SiteSetting::get('google_site_verification');
    @endphp
    @if($verification)
        <meta name="google-site-verification" content="{{ $verification }}">
    @endif

    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="flex min-h-screen flex-col">

{{-- Structured data: WebSite + SearchAction on home, Breadcrumbs everywhere --}}
@php
    $breadcrumbsItems = $breadcrumbs ?? [];
@endphp
@if(in_array($pageType ?? '', ['tool', 'category', 'blog_post', 'blog_category', 'static_page', 'home']))
    @php
        $ldBreadcrumbs = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect([['label' => 'Home', 'url' => url('/')], ...$breadcrumbsItems])
                ->values()
                ->map(fn ($item, $i) => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => $item['label'],
                    ...(isset($item['url']) && $item['url'] ? ['item' => $item['url']] : []),
                ])->all(),
        ];
    @endphp
    <script type="application/ld+json">{!! json_encode($ldBreadcrumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif

<header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/90 backdrop-blur"
        x-data="{ open: false, search: false }">
    <div class="container-app">
        <div class="flex h-16 items-center justify-between gap-4">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 shrink-0" aria-label="{{ $siteName }} home">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-600 to-accent-500 text-white shadow-sm">
                    <x-icon name="sparkles" class="h-5 w-5"/>
                </span>
                <span class="text-lg font-bold tracking-tight text-slate-900">{{ $siteName }}</span>
            </a>

            <nav class="hidden items-center gap-1 lg:flex" aria-label="Main navigation">
                <a href="{{ route('home') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900">Home</a>
                <a href="{{ route('tools.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900">All Tools</a>
                <a href="{{ route('blog.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900">Blog</a>
                <a href="{{ route('about') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900">About</a>
                <a href="{{ route('contact') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900">Contact</a>
            </nav>

            <div class="flex items-center gap-2">
                @include('partials.theme-toggle')
                <button type="button" class="btn-ghost hidden px-2.5 sm:inline-flex" @click="search = true" aria-label="Search tools">
                    <x-icon name="search" class="h-5 w-5"/>
                </button>
                <a href="{{ route('tools.index') }}" class="btn-accent hidden sm:inline-flex">Explore Tools</a>
                <button type="button" class="btn-ghost px-2.5 lg:hidden" @click="open = !open" aria-label="Toggle menu" :aria-expanded="open">
                    <x-icon name="menu" class="h-6 w-6" x-show="!open"/>
                    <x-icon name="x" class="h-6 w-6" x-show="open" x-cloak/>
                </button>
            </div>
        </div>

        {{-- Mobile menu --}}
        <nav x-show="open" x-cloak x-transition.opacity.duration.150ms
             class="border-t border-slate-100 py-3 lg:hidden" aria-label="Mobile navigation">
            <div class="flex flex-col gap-1">
                @foreach([['Home', route('home')], ['All Tools', route('tools.index')], ['Blog', route('blog.index')], ['About', route('about')], ['Contact', route('contact')]] as [$label, $url])
                    <a href="{{ $url }}" class="rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-100" @click="open = false">{{ $label }}</a>
                @endforeach
                <a href="{{ route('tools.index') }}" class="btn-accent mt-2 sm:hidden" @click="open = false">Explore Tools</a>
            </div>
        </nav>

        {{-- Tool search overlay --}}
        <div x-show="search" x-cloak x-transition.opacity.duration.150ms class="fixed inset-0 z-50 bg-slate-900/40 p-4 backdrop-blur-sm" @click.self="search = false" @keydown.escape.window="search = false">
            <div class="mx-auto mt-16 max-w-xl" x-data="{ q: '', results: [], loading: false }">
                <div class="card overflow-hidden p-0">
                    <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3">
                        <x-icon name="search" class="h-5 w-5 text-slate-400"/>
                        <input type="search" x-model="q" placeholder="Search tools…"
                               class="w-full border-0 text-base focus:ring-0"
                               aria-label="Search tools"
                               x-ref="input"
                               x-init="$watch('search', v => v && $nextTick(() => $refs.input.focus()))"
                               @input.debounce.250ms="
                                   loading = true;
                                   fetch(`/tools/search?q=${encodeURIComponent(q)}`).then(r => r.json()).then(d => { results = d.results; loading = false; }).catch(() => { loading = false; });">
                        <button type="button" class="btn-ghost px-2 py-1" @click="search = false" aria-label="Close search">
                            <x-icon name="x" class="h-5 w-5"/>
                        </button>
                    </div>
                    <ul class="max-h-80 overflow-y-auto p-2" role="listbox">
                        <template x-if="q && !loading && results.length === 0">
                            <li class="px-3 py-6 text-center text-sm text-slate-500">No tools match your search.</li>
                        </template>
                        <template x-for="result in results" :key="result.url">
                            <li>
                                <a :href="result.url" class="flex flex-col gap-0.5 rounded-xl px-3 py-2.5 hover:bg-brand-50" @click="search = false">
                                    <span class="text-sm font-semibold text-slate-900" x-text="result.name"></span>
                                    <span class="text-xs text-slate-500" x-text="result.description"></span>
                                </a>
                            </li>
                        </template>
                        <template x-if="loading">
                            <li class="px-3 py-6 text-center text-sm text-slate-400">Searching…</li>
                        </template>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</header>

<main class="flex-1">
    @if (session('success'))
        <div class="container-app mt-4">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="status">{{ session('success') }}</div>
        </div>
    @endif

    @yield('content')
</main>

<footer class="mt-16 border-t border-slate-200 bg-white">
    <div class="container-app py-12">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-600 to-accent-500 text-white">
                        <x-icon name="sparkles" class="h-4 w-4"/>
                    </span>
                    <span class="font-bold text-slate-900">{{ $siteName }}</span>
                </a>
                <p class="mt-3 text-sm leading-relaxed text-slate-600">
                    {{ \App\Support\SeoResolver::tagline() }} Free, fast, browser-based tools that respect your privacy — files never leave your device.
                </p>
            </div>

            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-900">Tool Categories</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach(\App\Models\Category::query()->active()->ordered()->get(['name', 'slug']) as $cat)
                        <li><a href="/{{ $cat->slug }}" class="text-slate-600 hover:text-brand-600">{{ $cat->name }}</a></li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-900">Company</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a href="{{ route('about') }}" class="text-slate-600 hover:text-brand-600">About Us</a></li>
                    <li><a href="{{ route('contact') }}" class="text-slate-600 hover:text-brand-600">Contact</a></li>
                    <li><a href="{{ route('blog.index') }}" class="text-slate-600 hover:text-brand-600">Blog</a></li>
                    <li><a href="{{ route('tools.index') }}" class="text-slate-600 hover:text-brand-600">All Tools</a></li>
                </ul>
            </div>

            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-900">Legal</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a href="{{ route('privacy') }}" class="text-slate-600 hover:text-brand-600">Privacy Policy</a></li>
                    <li><a href="{{ route('terms') }}" class="text-slate-600 hover:text-brand-600">Terms of Service</a></li>
                    <li><a href="{{ route('disclaimer') }}" class="text-slate-600 hover:text-brand-600">Disclaimer</a></li>
                    <li><a href="/sitemap.xml" class="text-slate-600 hover:text-brand-600">Sitemap</a></li>
                </ul>
            </div>
        </div>

        <div class="mt-10 border-t border-slate-100 pt-6">
            <p class="text-center text-sm text-slate-500">
                © {{ date('Y') }} {{ $siteName }}. All rights reserved.
                @if($note = \App\Models\SiteSetting::get('footer_note'))
                    — {{ $note }}
                @endif
            </p>
        </div>
    </div>
</footer>

@php
    $advertisementEnabled = \App\Models\SiteSetting::get('advertisement_enabled') === '1'
        && ($pageType ?? '') !== 'error';
@endphp
@if($advertisementEnabled && ($advertisementScript = \App\Models\SiteSetting::get('advertisement_script')))
    {!! $advertisementScript !!}
@endif

</body>
</html>
