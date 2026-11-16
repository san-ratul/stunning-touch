<!DOCTYPE html>
<html lang="en" data-theme="system">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Sign In') · {{ config('app.name') }} Admin</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    <script>(function(){try{var q=new URLSearchParams(location.search).get('theme');var t=(q==='dark'||q==='light'||q==='system')?q:(localStorage.getItem('st-theme')||'system');var d=t==='dark'||(t==='system'&&window.matchMedia('(prefers-color-scheme: dark)').matches);var el=document.documentElement;el.classList.toggle('dark',d);el.dataset.theme=t;}catch(e){}})();</script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="auth-body flex items-center justify-center p-4" x-data>

<div class="w-full max-w-md">
    <div class="mb-4 flex justify-end">
        @include('partials.theme-toggle')
    </div>

    @if(session('error'))
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">{{ session('error') }}</div>
    @endif

    <div class="mb-6 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-600 to-accent-500 text-white shadow-lg shadow-brand-600/30">
            <x-icon name="sparkles" class="h-7 w-7"/>
        </span>
        <h1 class="mt-4 text-2xl font-extrabold text-slate-900">{{ config('app.name') }} Admin</h1>
        <p class="mt-1 text-sm text-slate-500">Sign in to manage the platform</p>
    </div>

    @yield('content')

    <p class="mt-6 text-center text-xs text-slate-400">
        © {{ date('Y') }} {{ config('app.name') }} · <a href="{{ route('home') }}" class="hover:text-brand-600">Back to site</a>
    </p>
</div>

</body>
</html>
