@extends('layouts.admin')

@section('title', $category->exists ? 'Edit Category' : 'Create Category')

@section('content')
    <form method="POST" action="{{ $category->exists ? route('admin.categories.update', $category) : route('admin.categories.store') }}" class="max-w-2xl space-y-6">
        @csrf
        @if($category->exists) @method('PUT') @endif

        <div class="card p-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="name">Name *</label>
                    <input id="name" name="name" value="{{ old('name', $category->name) }}" required class="input" maxlength="100">
                </div>
                <div>
                    <label class="label" for="slug">Slug * (becomes /slug URL)</label>
                    <input id="slug" name="slug" value="{{ old('slug', $category->slug) }}" required class="input" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="image-tools">
                </div>
                <div>
                    <label class="label" for="icon">Icon name</label>
                    <input id="icon" name="icon" value="{{ old('icon', $category->icon) }}" class="input" placeholder="image, pdf, text, code, sparkles">
                </div>
                <div>
                    <label class="label" for="display_order">Display order</label>
                    <input id="display_order" type="number" name="display_order" min="0" value="{{ old('display_order', $category->display_order ?: 0) }}" class="input">
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="short_description">Short description</label>
                    <textarea id="short_description" name="short_description" rows="2" maxlength="500" class="input">{{ old('short_description', $category->short_description) }}</textarea>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="description">Full description (HTML, shown on category page)</label>
                    <textarea id="description" name="description" rows="6" class="input font-mono text-xs">{{ old('description', $category->description) }}</textarea>
                </div>
                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700">
                    <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('is_active', $category->exists ? $category->is_active : true) ? 'checked' : '' }}>
                    Active
                </label>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="btn-accent">{{ $category->exists ? 'Save Changes' : 'Create Category' }}</button>
            <a href="{{ route('admin.categories.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
@endsection
