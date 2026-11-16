@extends('layouts.admin')

@section('title', $tool->exists ? 'Edit Tool' : 'Create Tool')

@section('content')
    <form method="POST"
          action="{{ $tool->exists ? route('admin.tools.update', $tool) : route('admin.tools.store') }}"
          class="grid gap-6 lg:grid-cols-3">
        @csrf
        @if($tool->exists) @method('PUT') @endif

        <div class="space-y-6 lg:col-span-2">
            <div class="card p-6">
                <h2 class="mb-4 font-bold text-slate-900">Basic Information</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="name">Name *</label>
                        <input id="name" name="name" value="{{ old('name', $tool->name) }}" required class="input" maxlength="150">
                    </div>
                    <div>
                        <label class="label" for="slug">URL Slug *</label>
                        <input id="slug" name="slug" value="{{ old('slug', $tool->slug) }}" required class="input" placeholder="image-compressor" pattern="[a-z0-9]+(-[a-z0-9]+)*">
                        <p class="mt-1 text-xs text-slate-500">Public URL: /{{ old('slug', $tool->slug ?: 'your-slug') }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="short_description">Short description</label>
                        <textarea id="short_description" name="short_description" rows="2" maxlength="500" class="input">{{ old('short_description', $tool->short_description) }}</textarea>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="description">Full description (HTML)</label>
                        <textarea id="description" name="description" rows="8" class="input font-mono text-xs">{{ old('description', $tool->description) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="card p-6">
                <h2 class="mb-4 font-bold text-slate-900">How-To & FAQ (structured data)</h2>
                <div class="space-y-4">
                    <div>
                        <label class="label" for="how_to_title">How-to title</label>
                        <input id="how_to_title" name="how_to_title" value="{{ old('how_to_title', $tool->how_to_title) }}" class="input" placeholder="How to compress an image">
                    </div>
                    <div>
                        <label class="label">How-to steps (one per line)</label>
                        <textarea name="how_to_steps" rows="5" class="input">{{ old('how_to_steps', $tool->how_to_steps ? implode("\n", $tool->how_to_steps) : '') }}</textarea>
                    </div>
                    <div x-data="{{ json_encode([
                        'count' => old('faq', $tool->faq) ? max(2, count(old('faq', $tool->faq))) : 2,
                        'faqData' => old('faq', $tool->faq ?? []),
                    ]) }}">
                        <label class="label">FAQ items</label>
                        <template x-for="i in count" :key="i">
                            <div class="mb-3 grid gap-2 sm:grid-cols-2">
                                <input :name="`faq[${i-1}][q]`" placeholder="Question" class="input" :value="faqData[i-1]?.q || ''">
                                <input :name="`faq[${i-1}][a]`" placeholder="Answer" class="input" :value="faqData[i-1]?.a || ''">
                            </div>
                        </template>
                        <button type="button" class="btn-secondary mt-1" @click="count++">+ Add FAQ item</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card p-6">
                <h2 class="mb-4 font-bold text-slate-900">Configuration</h2>
                <div class="space-y-4">
                    <div>
                        <label class="label" for="category_id">Category *</label>
                        <select id="category_id" name="category_id" required class="input">
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" {{ old('category_id', $tool->category_id) == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="component_key">Functional Component *</label>
                        <select id="component_key" name="component_key" required class="input">
                            @foreach($registryKeys as $key)
                                <option value="{{ $key }}" {{ old('component_key', $tool->component_key) === $key ? 'selected' : '' }}>{{ $key }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-500">Only registered, safe components are selectable.</p>
                    </div>
                    <div>
                        <label class="label" for="icon">Icon name</label>
                        <input id="icon" name="icon" value="{{ old('icon', $tool->icon) }}" class="input" placeholder="image, pdf, text, code…">
                    </div>
                    <div>
                        <label class="label" for="display_order">Display order</label>
                        <input id="display_order" type="number" name="display_order" min="0" value="{{ old('display_order', $tool->display_order ?: 0) }}" class="input">
                    </div>

                    @foreach([['is_active', 'Active', $tool->exists ? $tool->is_active : true], ['is_featured', 'Featured', $tool->is_featured], ['is_archived', 'Archived', $tool->is_archived]] as [$field, $label, $checked])
                        <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700">
                            <input type="checkbox" name="{{ $field }}" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old($field, $checked) ? 'checked' : '' }}>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>

            <button type="submit" class="btn-accent btn-lg w-full">{{ $tool->exists ? 'Save Changes' : 'Create Tool' }}</button>
        </div>
    </div>
@endsection
