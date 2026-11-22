<!DOCTYPE html>
<html lang="en" data-theme="system">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }} Admin</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    <script>(function(){try{var q=new URLSearchParams(location.search).get('theme');var t=(q==='dark'||q==='light'||q==='system')?q:(localStorage.getItem('st-theme')||'system');var d=t==='dark'||(t==='system'&&window.matchMedia('(prefers-color-scheme: dark)').matches);var el=document.documentElement;el.classList.toggle('dark',d);el.dataset.theme=t;}catch(e){}})();</script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('scripts')
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="admin-body" x-data="{ sidebarOpen: false }">

<div class="flex min-h-screen">

    {{-- Sidebar --}}
    <aside class="admin-sidebar fixed inset-y-0 left-0 z-40 flex w-[264px] flex-col transition-transform duration-200"
           :class="sidebarOpen ? 'translate-x-0 shadow-2xl' : '-translate-x-full lg:translate-x-0'"
           aria-label="Admin navigation">
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-slate-200/80 px-5 dark:border-white/5">
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-accent-500 text-white shadow-lg shadow-brand-600/30">
                <x-icon name="sparkles" class="h-5 w-5"/>
            </span>
            <div class="leading-tight">
                <p class="text-sm font-bold text-slate-900 dark:text-white">{{ config('app.name') }}</p>
                <p class="text-[11px] font-medium text-slate-500 dark:text-[#7e8dab]">Admin Panel</p>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 pb-4">
            @php
                $navGroups = [
                    'Overview' => [
                        ['dashboard', 'Dashboard', 'chart', 'admin.dashboard'],
                    ],
                    'Content' => [
                        ['tools', 'Tools', 'tools', 'admin.tools.index'],
                        ['categories', 'Categories', 'doc', 'admin.categories.index'],
                        ['blog', 'Blog Posts', 'blog', 'admin.blog.posts.index'],
                        ['blog-categories', 'Blog Categories', 'blog', 'admin.blog.categories.index'],
                        ['blog-tags', 'Blog Tags', 'blog', 'admin.blog.tags.index'],
                        ['pages', 'Static Pages', 'doc', 'admin.pages.index'],
                        ['media', 'Media Library', 'image', 'admin.media.index'],
                    ],
                    'Insights' => [
                        ['analytics', 'Visitor Analytics', 'chart', 'admin.analytics.index'],
                        ['bot-protection', 'Bot Protection', 'shield', 'admin.bot-protection.index'],
                        ['tool-statistics', 'Tool Statistics', 'chart', 'admin.tool-statistics.index'],
                        ['monitoring', 'AI Monitoring', 'sparkles', 'admin.monitoring.index'],
                    ],
                    'Growth' => [
                        ['ads', 'Advertisement', 'megaphone', 'admin.advertisements.edit'],
                        ['seo', 'SEO Manager', 'search', 'admin.seo.index'],
                    ],
                    'System' => [
                        ['ai', 'AI Provider', 'sparkles', 'admin.ai-settings.edit'],
                        ['automation', 'Automation', 'clock', 'admin.automation.edit'],
                        ['research', 'Research Settings', 'search', 'admin.research.edit'],
                        ['settings', 'Site Settings', 'settings', 'admin.settings.edit'],
                        ['users', 'Admin Users', 'users', 'admin.users.index'],
                        ['account', 'My Account', 'shield', 'admin.account.password'],
                        ['audit', 'Audit Logs', 'shield', 'admin.audit-logs.index'],
                        ['messages', 'Messages', 'doc', 'admin.contact-messages.index'],
                        ...(\Illuminate\Support\Facades\Gate::allows('artisan-runner.access') ? [['artisan', 'Artisan Runner', 'tools', 'admin.artisan-runner.index']] : []),
                    ],
                ];
            @endphp

            @foreach($navGroups as $groupLabel => $items)
                <div>
                    <p class="sidebar-label">{{ $groupLabel }}</p>
                    <div class="space-y-0.5">
                        @foreach($items as [$key, $label, $icon, $route])
                            @php
                                $sectionPrefix = str_replace(['.index', '.edit'], '', $route);
                                $active = request()->routeIs($route) || request()->routeIs($sectionPrefix.'.*') || ($key === 'blog' && request()->is('admin/blog/posts*'));
                            @endphp
                            <a href="{{ route($route) }}" class="sidebar-link {{ $active ? 'active' : '' }}">
                                <x-icon name="{{ $icon }}" class="h-5 w-5 shrink-0 opacity-90"/>
                                <span>{{ $label }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        <div class="sidebar-user shrink-0 p-3">
            <div class="flex items-center gap-3 px-2 py-2">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-accent-500 text-sm font-bold text-white">
                    {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
                </span>
                <div class="min-w-0 leading-tight">
                    <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ auth()->user()->name ?? 'Admin' }}</p>
                    <p class="truncate text-[11px] text-slate-500 dark:text-[#7e8dab]">{{ auth()->user()?->email }}</p>
                </div>
            </div>
            <div class="flex gap-2 px-1 pt-1">
                <a href="{{ route('home') }}" target="_blank" class="sidebar-action">
                    <x-icon name="globe" class="h-3.5 w-3.5"/> View site
                </a>
                <form method="POST" action="{{ route('admin.logout') }}" class="flex flex-1">
                    @csrf
                    <button type="submit" class="sidebar-action sidebar-action-danger w-full">
                        <x-icon name="x" class="h-3.5 w-3.5"/> Sign out
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- Mobile sidebar overlay --}}
    <div x-show="sidebarOpen" x-cloak x-transition.opacity.duration.150ms
         class="fixed inset-0 z-30 bg-slate-900/50 backdrop-blur-sm lg:hidden"
         @click="sidebarOpen = false" aria-hidden="true"></div>

    {{-- Main --}}
    <div class="flex min-w-0 flex-1 flex-col lg:pl-[264px]">
        <header class="admin-topbar sticky top-0 z-20 flex h-16 items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 sm:px-6 dark:border-white/5 dark:bg-[#0d1526]">
            <div class="flex items-center gap-3">
                <button type="button" class="btn-ghost px-2 lg:hidden" @click="sidebarOpen = !sidebarOpen" aria-label="Toggle sidebar">
                    <x-icon name="menu" class="h-6 w-6"/>
                </button>
                <h1 class="truncate text-lg font-bold text-slate-900 dark:text-slate-100">@yield('title', 'Dashboard')</h1>
            </div>
            <div class="flex items-center gap-1.5" x-data="{ menu: false }">
                @include('partials.theme-toggle')
                <a href="{{ route('home') }}" target="_blank" class="btn-ghost hidden px-3 py-1.5 text-sm sm:inline-flex">
                    <x-icon name="globe" class="h-4 w-4"/> View site
                </a>
                <div class="relative">
                    <button type="button" class="btn-ghost px-2" @click="menu = !menu" :aria-expanded="menu" aria-label="Account menu">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-accent-500 text-sm font-bold text-white">
                            {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
                        </span>
                    </button>
                    <div x-show="menu" x-cloak x-transition.opacity.duration.150ms @click.outside="menu = false"
                         class="absolute right-0 mt-2 w-52 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-700 dark:bg-slate-800">
                        <div class="border-b border-slate-100 px-3 pb-2 pt-1.5 dark:border-slate-700">
                            <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ auth()->user()->name ?? 'Admin' }}</p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ auth()->user()?->email }}</p>
                        </div>
                        <a href="{{ route('admin.account.password') }}" class="mt-1 block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-800 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-700">
                            Change password
                        </a>
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit" class="mt-1 w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6">
            @if(session('success'))
                <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="status">{{ session('success') }}</div>
            @endif
            @if(session('warning'))
                <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800" role="status">{{ session('warning') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                    <p class="font-semibold">Please fix the following:</p>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

@stack('bottom-scripts')
</body>
</html>
