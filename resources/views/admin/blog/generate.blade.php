@extends('layouts.admin')

@section('title', 'Generate New Blog')

@section('content')
    @php($minWords = (int) ($automation->min_word_count ?: 900))
    @php($maxWords = (int) ($automation->max_word_count ?: 2000))

    @if(session('generation_result'))
        @php($result = session('generation_result'))
        <div class="card mb-6 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-bold text-slate-900">Draft generated{{ isset($result['title']) ? ': '.\Illuminate\Support\Str::limit($result['title'], 80) : '' }}</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Status: <strong class="capitalize">{{ $result['status'] ?? 'draft' }}</strong> (never auto-published)
                        @isset($result['word_count'])
                            · {{ $result['word_count'] }} words
                        @endisset
                        @if($result['research_used'] ?? false)
                            · {{ $result['research_sources'] ?? 0 }} research source(s) linked
                        @endif
                        @if($result['prompt_tokens'] || $result['completion_tokens'] ?? false)
                            · tokens: {{ $result['prompt_tokens'] ?? 0 }} prompt / {{ $result['completion_tokens'] ?? 0 }} completion
                        @endif
                        · image: @if($result['image_attached'] ?? false)
                            <strong>{{ $result['image_provider'] === 'cloudflare' ? 'Cloudflare FLUX.1' : 'local branded cover' }}</strong>
                        @else
                            none attached
                        @endif
                    </p>
                    @if(($result['image_warning'] ?? null) !== null)
                        <p class="mt-1 text-xs text-amber-700">Image note: {{ $result['image_warning'] }}</p>
                    @endif
                </div>
                <a href="{{ route('admin.blog.posts.edit', $result['post_id']) }}" class="btn-accent">Open & Edit Article</a>
            </div>

            <div class="mt-4 border-t border-slate-100 pt-4">
                <p class="text-sm font-semibold {{ ($result['passed'] ?? false) ? 'text-emerald-700' : 'text-red-700' }}">
                    Quality check: {{ ($result['passed'] ?? false) ? 'passed' : 'failed' }}
                </p>
                @if(($result['violations'] ?? []) !== [])
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700">
                        @foreach($result['violations'] as $violation)
                            <li>{{ $violation }}</li>
                        @endforeach
                    </ul>
                @endif
                @if(($result['warnings'] ?? []) !== [])
                    <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-amber-700">Warnings</p>
                    <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-amber-800">
                        @foreach($result['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    @if(! $providerState['enabled'])
        <div class="card mb-6 border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            The AI provider is currently <strong>disabled</strong>. Enable it in <a href="{{ route('admin.ai-settings.edit') }}" class="underline">AI Provider settings</a> before generating.
        </div>
    @elseif(! $providerState['configured'])
        <div class="card mb-6 border-red-200 bg-red-50 p-4 text-sm text-red-800">
            The AI provider is <strong>incomplete</strong> — missing: {{ implode(', ', $providerState['missing']) }}.
            <a href="{{ route('admin.ai-settings.edit') }}" class="underline">Fix it in AI Provider settings</a>.
        </div>
    @endif

    <form method="POST" action="{{ route('admin.blog.generate.store') }}"
          x-data="{ submitting: false }" @submit="setTimeout(() => submitting = true, 0)"
          class="grid max-w-4xl gap-6 md:grid-cols-2">
        @csrf

        <div class="card p-6 md:col-span-2">
            <h2 class="mb-1 font-bold text-slate-900">Generate a new blog draft</h2>
            <p class="mb-5 text-sm text-slate-600">
                The article is written by the configured AI provider ({{ $providerState['auth_mode'] === 'bearer' ? 'authenticated' : ucfirst($providerState['auth_mode']) }} mode),
                saved as a <strong>draft for review</strong>, and quality-checked. This never publishes automatically and does not depend on the schedule.
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="label" for="topic">Topic or content idea *</label>
                    <textarea id="topic" name="topic" rows="2" class="input" placeholder="e.g. How to convert text case online without installing software" maxlength="400">{{ old('topic') }}</textarea>
                    @error('topic')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="focus_keyword">Focus keyword *</label>
                    <input id="focus_keyword" name="focus_keyword" value="{{ old('focus_keyword') }}" class="input" maxlength="100" placeholder="convert text case">
                    @error('focus_keyword')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="secondary_keywords">Secondary keywords (comma-separated)</label>
                    <input id="secondary_keywords" name="secondary_keywords" value="{{ old('secondary_keywords') }}" class="input" placeholder="case converter, uppercase online">
                    @error('secondary_keywords')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="target_word_count">Target word count ({{ $minWords }}–{{ $maxWords }})</label>
                    <input id="target_word_count" name="target_word_count" type="number" min="{{ $minWords }}" max="{{ $maxWords }}" value="{{ old('target_word_count') }}" class="input">
                    @error('target_word_count')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label" for="blog_category">Preferred category</label>
                    <select id="blog_category" name="blog_category" class="input">
                        <option value="">Let the AI decide</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" {{ old('blog_category') == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('blog_category')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="instructions">Additional writing instructions</label>
                    <textarea id="instructions" name="instructions" rows="3" class="input" maxlength="1000" placeholder="e.g. Focus on small-business use cases, mention the tool early, avoid jargon.">{{ old('instructions') }}</textarea>
                    @error('instructions')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700">
                    <input type="checkbox" name="research" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('research', $automation->research_enabled) ? 'checked' : '' }}>
                    Research real sources first ({{ $automation->research_enabled ? 'enabled' : 'globally disabled' }} in Automation)
                </label>
                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700">
                    <input type="checkbox" name="force" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('force') ? 'checked' : '' }}>
                    Generate anyway if a similar post already exists
                </label>
            </div>
        </div>

        <div class="md:col-span-2">
            <button type="submit" class="btn-accent" :disabled="submitting" :class="{ 'opacity-60 cursor-not-allowed': submitting }">
                <span x-show="!submitting">✨ Generate Draft Article</span>
                <span x-show="submitting" x-cloak>Generating… this can take a couple of minutes — please wait</span>
            </button>
            <p class="mt-2 text-xs text-slate-500">Duplicate submissions are prevented: the button locks while generating and the shared generation lock blocks parallel runs.</p>
        </div>
    </form>
@endsection
