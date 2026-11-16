@extends('layouts.admin')

@section('title', 'Research Settings')

@section('content')
    <form method="POST" action="{{ route('admin.research.update') }}" class="grid max-w-4xl gap-6 md:grid-cols-2">
        @csrf
        @method('PUT')

        <div class="card p-6 md:col-span-2">
            <h2 class="mb-1 font-bold text-slate-900">Research Sources</h2>
            <p class="mb-5 text-sm text-slate-600">
                Articles are grounded in real external research. Wikipedia and Hacker News are always queried when research is
                enabled; RSS feeds and the optional web-search provider broaden topic discovery. Every source URL and fetch
                timestamp is stored on the article. No search volumes or statistics are invented — the system only reports what
                real sources actually provide.
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700 sm:col-span-2">
                    <input type="checkbox" name="enabled" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('enabled', $settings->enabled) ? 'checked' : '' }}>
                    Enable research before article generation
                </label>
                <div>
                    <label class="label" for="sources_per_source">Max results per source</label>
                    <input id="sources_per_source" name="sources_per_source" type="number" min="1" max="10" value="{{ old('sources_per_source', $settings->sources_per_source) }}" class="input">
                </div>
                <div>
                    <label class="label" for="provider">Web search provider (optional)</label>
                    <select id="provider" name="provider" class="input">
                        <option value="none" {{ old('provider', $settings->provider) === 'none' ? 'selected' : '' }}>None (Wikipedia, HN & RSS only)</option>
                        <option value="tavily" {{ old('provider', $settings->provider) === 'tavily' ? 'selected' : '' }}>Tavily</option>
                        <option value="serpapi" {{ old('provider', $settings->provider) === 'serpapi' ? 'selected' : '' }}>SerpApi</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="api_key">Web search API key {{ $settings->api_key ? '(stored — leave blank to keep)' : '' }}</label>
                    <input id="api_key" name="api_key" type="password" class="input" autocomplete="new-password">
                    <p class="mt-1 text-xs text-slate-500">Required only when a web-search provider is selected. Stored encrypted; never displayed or sent to the browser.</p>
                </div>
            </div>
        </div>

        <div class="card p-6">
            <h2 class="mb-4 font-bold text-slate-900">RSS feeds</h2>
            <label class="label" for="rss_feeds">Feed URLs (one per line, max 10)</label>
            <textarea id="rss_feeds" name="rss_feeds" rows="6" class="input" placeholder="https://example.com/feed">{{ old('rss_feeds', $settings->rss_feeds ? implode("\n", $settings->rss_feeds) : '') }}</textarea>
            <p class="mt-1 text-xs text-slate-500">Admin-curated feeds are trusted by configuration and used for research and topic discovery.</p>
        </div>

        <div class="card p-6">
            <h2 class="mb-4 font-bold text-slate-900">Trusted domains</h2>
            <label class="label" for="trusted_domains">Whitelist (one per line or comma-separated)</label>
            <textarea id="trusted_domains" name="trusted_domains" rows="6" class="input" placeholder="en.wikipedia.org{{ "\n" }}news.ycombinator.com">{{ old('trusted_domains', $settings->trusted_domains ? implode("\n", $settings->trusted_domains) : '') }}</textarea>
            <p class="mt-1 text-xs text-slate-500">When set, web/HN/Wikipedia results outside this whitelist are discarded. Leave empty to accept all results.</p>
        </div>

        <div class="md:col-span-2">
            <button type="submit" class="btn-accent">Save Research Settings</button>
        </div>
    </form>
@endsection
