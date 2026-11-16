@extends('layouts.public')

@section('content')
    <div class="container-app py-10">
        @include('partials.breadcrumbs', ['breadcrumbs' => [['label' => 'All Tools']]])

        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">All Online Tools</h1>
        <p class="mt-3 max-w-2xl text-slate-600">
            {{ \App\Support\SeoResolver::siteName() }} has {{ $categories->sum(fn ($c) => $c->activeTools->count()) }} free tools
            across {{ $categories->count() }} categories. Pick a tool and get things done — no sign-up required.
        </p>

        @if($ad)
            {!! $ad !!}
        @endif

        <div class="mt-10 space-y-12">
            @foreach($categories as $category)
                <section aria-labelledby="cat-{{ $category->id }}">
                    <div class="mb-5 flex items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-brand-600/10 to-accent-500/10 text-brand-600 ring-1 ring-brand-600/10">
                            <x-icon name="{{ $category->icon ?: 'tools' }}" class="h-5 w-5"/>
                        </span>
                        <div>
                            <h2 id="cat-{{ $category->id }}" class="text-xl font-bold text-slate-900">
                                <a href="/{{ $category->slug }}" class="hover:text-brand-600">{{ $category->name }}</a>
                            </h2>
                            @if($category->short_description)
                                <p class="text-sm text-slate-500">{{ $category->short_description }}</p>
                            @endif
                        </div>
                    </div>

                    @if($category->activeTools->isEmpty())
                        <p class="text-sm text-slate-500">Tools in this category are coming soon.</p>
                    @else
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach($category->activeTools as $tool)
                                <x-tool-card :tool="$tool"/>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
@endsection
