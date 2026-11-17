{{-- SEO head: unique metadata per page, all server-rendered --}}
@php
    $seo = $seo ?? [];
    $fullTitle = \App\Support\SeoResolver::fullTitle($seo['title'] ?? '');
    $description = $seo['description'] ?? null;
    // Canonical host always comes from APP_URL — never the requesting host.
    $canonical = \App\Support\SeoResolver::absoluteUrl($seo['canonical'] ?? request()->getPathInfo());

    // og:image: page-specific -> featured image (mapped upstream) -> site default.
    $ogImage = \App\Support\SeoResolver::absoluteUrl($seo['og_image'] ?? null);
    $ogImageIsDefault = blank($ogImage);
    if ($ogImageIsDefault) {
        $ogImage = \App\Support\SeoResolver::defaultOgImage();
    }

    // twitter:image: page-specific -> og:image -> site default.
    $twitterImage = \App\Support\SeoResolver::absoluteUrl($seo['twitter_image'] ?? null) ?? $ogImage;

    // og:description: resolved og_description -> standard description.
    $ogDescription = $seo['og_description'] ?? $description;
    // twitter:description: resolved twitter_description -> og:description -> description.
    $twitterDescription = $seo['twitter_description'] ?? $ogDescription;

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
@if(!blank($ogDescription))
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit($ogDescription, 200) }}">
@endif
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:image" content="{{ $ogImage }}">
@if($ogImageIsDefault)
    {{-- Metadata is only known for the bundled default image. --}}
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
@endif

{{-- Twitter --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ \Illuminate\Support\Str::limit($seo['twitter_title'] ?? $fullTitle, 90) }}">
@if(!blank($twitterDescription))
    <meta name="twitter:description" content="{{ \Illuminate\Support\Str::limit($twitterDescription, 200) }}">
@endif
<meta name="twitter:image" content="{{ $twitterImage }}">
