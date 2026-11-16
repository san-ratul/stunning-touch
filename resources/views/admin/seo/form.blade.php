@extends('layouts.admin')

@section('title', 'Edit SEO — '.$entity->{$titleField})

@section('content')
    <form method="POST" action="{{ route('admin.seo.update', [$type, $entity->id]) }}" class="grid gap-6 xl:grid-cols-2">
        @csrf
        @method('PUT')

        <div class="space-y-6">
            <div class="card p-6">
                <h2 class="mb-4 font-bold text-slate-900">Core Metadata</h2>
                <div class="space-y-4">
                    <div>
                        <label class="label" for="meta_title">Meta title (≤ 60 chars recommended)</label>
                        <input id="meta_title" name="meta_title" value="{{ old('meta_title', $seo?->meta_title) }}" maxlength="255" class="input" data-counter="60">
                        <p class="counter-note mt-1 text-xs text-slate-500"></p>
                    </div>
                    <div>
                        <label class="label" for="meta_description">Meta description (≤ 155 chars recommended)</label>
                        <textarea id="meta_description" name="meta_description" rows="3" maxlength="500" class="input" data-counter="155">{{ old('meta_description', $seo?->meta_description) }}</textarea>
                        <p class="counter-note mt-1 text-xs text-slate-500"></p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label" for="focus_keyword">Focus keyword</label>
                            <input id="focus_keyword" name="focus_keyword" value="{{ old('focus_keyword', $seo?->focus_keyword) }}" class="input">
                        </div>
                        <div>
                            <label class="label" for="secondary_keywords">Secondary keywords (comma-separated)</label>
                            <input id="secondary_keywords" name="secondary_keywords" value="{{ old('secondary_keywords', $seo?->secondary_keywords) }}" class="input">
                        </div>
                    </div>
                    <div>
                        <label class="label" for="canonical_url">Canonical URL (override, usually leave empty)</label>
                        <input id="canonical_url" name="canonical_url" value="{{ old('canonical_url', $seo?->canonical_url) }}" type="url" class="input" placeholder="https://stunningtouch.com/…">
                    </div>
                </div>
            </div>

            <div class="card p-6">
                <h2 class="mb-4 font-bold text-slate-900">Robots & Schema</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700">
                        <input type="hidden" name="robots_index" value="0">
                        <input type="checkbox" name="robots_index" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('robots_index', $seo?->robots_index ?? true) ? 'checked' : '' }}>
                        Allow indexing (robots_index)
                    </label>
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700">
                        <input type="hidden" name="robots_follow" value="0">
                        <input type="checkbox" name="robots_follow" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('robots_follow', $seo?->robots_follow ?? true) ? 'checked' : '' }}>
                        Allow following links (robots_follow)
                    </label>
                    <div>
                        <label class="label" for="schema_type">Schema type</label>
                        <select id="schema_type" name="schema_type" class="input">
                            @foreach(['', 'WebPage', 'SoftwareApplication', 'HowTo', 'FAQPage', 'Article', 'BreadcrumbList'] as $type)
                                <option value="{{ $type }}" {{ old('schema_type', $seo?->schema_type) === $type ? 'selected' : '' }}>{{ $type ?: '— none —' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="schema_data">Schema data (JSON, optional)</label>
                        <textarea id="schema_data" name="schema_data" rows="4" class="input font-mono text-xs" placeholder='{"rating": {"@type": "AggregateRating"}}'>{!! old('schema_data', $seo?->schema_data ? json_encode($seo->schema_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') !!}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card p-6">
                <h2 class="mb-4 font-bold text-slate-900">Open Graph (Facebook, LinkedIn…)</h2>
                <div class="space-y-4">
                    <div>
                        <label class="label" for="og_title">OG title</label>
                        <input id="og_title" name="og_title" value="{{ old('og_title', $seo?->og_title) }}" maxlength="255" class="input">
                    </div>
                    <div>
                        <label class="label" for="og_description">OG description</label>
                        <textarea id="og_description" name="og_description" rows="3" maxlength="500" class="input">{{ old('og_description', $seo?->og_description) }}</textarea>
                    </div>
                    <div>
                        <label class="label" for="og_image">OG image (URL or /storage path)</label>
                        <input id="og_image" name="og_image" value="{{ old('og_image', $seo?->og_image) }}" class="input" placeholder="/storage/media/og-home.jpg">
                    </div>
                </div>
            </div>

            <div class="card p-6">
                <h2 class="mb-4 font-bold text-slate-900">X (Twitter) Card</h2>
                <p class="mb-4 text-xs text-slate-500 dark:text-slate-400">Shared as <code>twitter:*</code> meta tags — consumed by X and most social platforms.</p>
                <div class="space-y-4">
                    <div>
                        <label class="label" for="twitter_title">X title</label>
                        <input id="twitter_title" name="twitter_title" value="{{ old('twitter_title', $seo?->twitter_title) }}" maxlength="255" class="input">
                    </div>
                    <div>
                        <label class="label" for="twitter_description">X description</label>
                        <textarea id="twitter_description" name="twitter_description" rows="3" maxlength="500" class="input">{{ old('twitter_description', $seo?->twitter_description) }}</textarea>
                    </div>
                    <div>
                        <label class="label" for="twitter_image">X image</label>
                        <input id="twitter_image" name="twitter_image" value="{{ old('twitter_image', $seo?->twitter_image) }}" class="input">
                    </div>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="btn-accent">Save SEO Metadata</button>
                <a href="{{ route('admin.seo.index') }}" class="btn-secondary">Back</a>
            </div>
        </div>
    </div>
@endsection

@push('bottom-scripts')
    <script>
        document.querySelectorAll('[data-counter]').forEach((input) => {
            const limit = Number(input.dataset.counter);
            const note = input.closest('div').querySelector('.counter-note');
            const update = () => {
                const len = input.value.length;
                note.textContent = `${len} / ${limit} characters ${len <= limit ? '✓' : '⚠ over limit'}`;
                note.className = `counter-note mt-1 text-xs ${len <= limit ? 'text-slate-500' : 'text-amber-600 font-medium'}`;
            };
            input.addEventListener('input', update);
            update();
        });
    </script>
@endpush
