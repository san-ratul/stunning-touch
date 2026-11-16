@extends('layouts.admin')

@section('title', 'Edit Page — '.$page->title)

@section('content')
    <form method="POST" action="{{ route('admin.pages.update', $page) }}" class="max-w-3xl space-y-6">
        @csrf
        @method('PUT')

        <div class="card space-y-4 p-6">
            <div>
                <label class="label" for="title">Title *</label>
                <input id="title" name="title" value="{{ old('title', $page->title) }}" required class="input" maxlength="150">
            </div>
            <div>
                <label class="label" for="content">Content (HTML)</label>
                <textarea id="content" name="content" rows="20" class="input font-mono text-[13px] scroll-thin">{{ old('content', $page->content) }}</textarea>
            </div>
            <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700">
                <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('is_active', $page->is_active) ? 'checked' : '' }}>
                Live (visible on the site)
            </label>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="btn-accent">Save Page</button>
            <a href="/{{ $page->slug }}" target="_blank" class="btn-secondary">Preview on site</a>
        </div>
    </form>
@endsection
