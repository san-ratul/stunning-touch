@extends('layouts.public')

@section('content')
    <div class="container-app py-10">
        @include('partials.breadcrumbs', ['breadcrumbs' => $breadcrumbs ?? []])

        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ $blogCategory->name }}</h1>
        @if($blogCategory->description)
            <p class="mt-3 max-w-2xl text-slate-600">{{ $blogCategory->description }}</p>
        @endif

        <div class="mt-8 grid gap-10 lg:grid-cols-[1fr_300px]">
            <div>
                @if($posts->isEmpty())
                    <div class="card p-10 text-center"><p class="text-slate-500">No articles in this category yet.</p></div>
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
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-900">All Categories</h2>
                    <ul class="mt-3 space-y-2">
                        @foreach($blogCategories as $cat)
                            <li>
                                <a href="{{ route('blog.category', $cat->slug) }}"
                                   class="text-sm {{ $cat->id === $blogCategory->id ? 'font-semibold text-brand-700' : 'text-slate-600 hover:text-brand-600' }}">
                                    {{ $cat->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </aside>
        </div>
    </div>
@endsection
