@extends('layouts.public')

@section('content')
    <article class="container-app py-10">
        @include('partials.breadcrumbs', ['breadcrumbs' => $breadcrumbs ?? [['label' => $page->title]]])

        @if(!$page)
            <h1 class="text-3xl font-extrabold text-slate-900">{{ $seo['title'] }}</h1>
            <p class="mt-4 text-slate-600">This page has not been configured yet.</p>
        @else
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ $page->title }}</h1>
            <div class="prose-article mt-8 max-w-3xl">{!! $page->content !!}</div>
        @endif
    </article>
@endsection
