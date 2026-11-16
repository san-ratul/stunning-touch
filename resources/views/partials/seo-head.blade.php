{{-- SEO head: unique metadata per page, all server-rendered --}}
@php
    $seo = $seo ?? [];
    $fullTitle = \App\Support\SeoResolver::fullTitle($seo['title'] ?? '');
    $description = $seo['description'] ?? null;
    $canonical = \App\Support\SeoResolver::absoluteUrl($seo['canonical'] ?? url()->current());
    $ogImage = \App\Support\SeoResolver::absoluteUrl($seo['og_image'] ?? $seo['twitter_image'] ?? null);
    $siteName = \App\Support\SeoResolver::siteName();
@endphp

<title>{{ $fullTitle }}</title>

<meta name="description" content="{{ \Illuminate\Support\Str::limit($description ?? '', 158) }}">
@if(!empty($seo['focus_keyword']))
    <meta name="keywords" content="{{ collect([$seo['focus_keyword'], ...explode(',', (string) $seo['secondary_keywords'])])->filter()->map(fn ($k) => trim($k))->implode(', ') }}">
@endif

<link rel="canonical" href="{{ $canonical }}">
@if(!($seo['robots_index'] ?? true) || !($seo['robots_follow'] ?? true))
    <meta name="robots" content="{{ (($seo['robots_index'] ?? true) ? 'index' : 'noindex').', '.(($seo['robots_follow'] ?? true) ? 'follow' : 'nofollow') }}">
@endif

{{-- Open Graph --}}
<meta property="og:type" content="{{ ($seo['schema_type'] ?? '') === 'Article' ? 'article' : 'website' }}">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:title" content="{{ \Illuminate\Support\Str::limit($seo['og_title'] ?? $fullTitle, 90) }}">
@if($description)
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit($description, 200) }}">
@endif
<meta property="og:url" content="{{ $canonical }}">
@if($ogImage)
    <meta property="og:image" content="{{ $ogImage }}">
@endif

{{-- Twitter --}}
<meta name="twitter:card" content="{{ $ogImage ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ \Illuminate\Support\Str::limit($seo['twitter_title'] ?? $fullTitle, 90) }}">
@if($description)
    <meta name="twitter:description" content="{{ \Illuminate\Support\Str::limit($description, 200) }}">
@endif
@if($ogImage)
    <meta name="twitter:image" content="{{ $ogImage }}">
@endif
