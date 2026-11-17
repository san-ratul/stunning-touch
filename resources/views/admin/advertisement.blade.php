@extends('layouts.admin')

@section('title', 'Advertisement')

@section('content')
    <form method="POST" action="{{ route('admin.advertisements.update') }}" class="max-w-3xl space-y-6">
        @csrf
        @method('PUT')

        <div class="card p-6">
            <h2 class="mb-4 font-bold text-slate-900">Advertisement Status</h2>
            <label class="label" for="advertisement_enabled">Advertisement Status</label>
            <select id="advertisement_enabled" name="advertisement_enabled" class="input max-w-xs">
                <option value="1" @selected($enabled)>Enabled</option>
                <option value="0" @selected(! $enabled)>Disabled</option>
            </select>
            <p class="mt-2 text-sm text-slate-500">
                When enabled, the script below is rendered on every public page immediately before the closing body tag.
                Admin pages, login, sitemap and robots are never affected.
            </p>
        </div>

        <div class="card p-6">
            <h2 class="mb-4 font-bold text-slate-900">Advertisement Script</h2>
            <label class="label" for="advertisement_script">Advertisement Script</label>
            <textarea id="advertisement_script" name="advertisement_script" rows="14" spellcheck="false"
                      class="input font-mono text-xs leading-relaxed"
                      placeholder="<script>...</script>">{{ old('advertisement_script', $script) }}</textarea>
            <p class="mt-2 text-sm text-slate-500">
                Paste the raw ad network snippet (e.g. Monetag). The code is stored and rendered exactly as supplied —
                script tags may be pasted directly, and multiple script tags are supported. It never runs inside the admin panel.
            </p>
        </div>

        <button type="submit" class="btn-accent">Save Advertisement</button>
    </form>
@endsection
