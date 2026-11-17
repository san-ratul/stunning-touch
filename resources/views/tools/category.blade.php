@extends('layouts.public')

@section('content')
    <div class="container-app py-10">
        @include('partials.breadcrumbs', ['breadcrumbs' => $breadcrumbs ?? []])

        <div class="flex items-start gap-4">
            @if($category->icon)
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-600 to-accent-500 text-white shadow-md">
                    <x-icon name="{{ $category->icon }}" class="h-7 w-7"/>
                </span>
            @endif
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ $category->name }}</h1>
                @if($category->short_description)
                    <p class="mt-2 max-w-2xl text-slate-600">{{ $category->short_description }}</p>
                @endif
            </div>
        </div>

        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($tools as $tool)
                <x-tool-card :tool="$tool"/>
            @endforeach
        </div>

        @if($category->description)
            <div class="prose-article mt-12 max-w-3xl">{!! $category->description !!}</div>
        @endif
    </div>
@endsection
