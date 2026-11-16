@extends('layouts.admin')

@section('title', $category->exists ? 'Edit Blog Category' : 'Create Blog Category')

@section('content')
    <form method="POST" action="{{ $category->exists ? route('admin.blog.categories.update', $category) : route('admin.blog.categories.store') }}" class="max-w-xl space-y-6">
        @csrf
        @if($category->exists) @method('PUT') @endif

        <div class="card space-y-4 p-6">
            <div>
                <label class="label" for="name">Name *</label>
                <input id="name" name="name" value="{{ old('name', $category->name) }}" required class="input" maxlength="100">
            </div>
            <div>
                <label class="label" for="slug">Slug *</label>
                <input id="slug" name="slug" value="{{ old('slug', $category->slug) }}" required class="input" pattern="[a-z0-9]+(-[a-z0-9]+)*">
            </div>
            <div>
                <label class="label" for="description">Description</label>
                <textarea id="description" name="description" rows="3" maxlength="500" class="input">{{ old('description', $category->description) }}</textarea>
            </div>
            <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700">
                <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('is_active', $category->exists ? $category->is_active : true) ? 'checked' : '' }}>
                Active
            </label>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="btn-accent">{{ $category->exists ? 'Save Changes' : 'Create Category' }}</button>
            <a href="{{ route('admin.blog.categories.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
@endsection
