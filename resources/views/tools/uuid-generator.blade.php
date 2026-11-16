@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}">
    <div class="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
        <div>
            <label class="label" for="uuid-count">How many UUIDs?</label>
            <input id="uuid-count" type="number" min="1" max="100" value="5" class="input" data-count>
        </div>
        <button type="button" class="btn-accent btn-lg" data-generate>Generate UUIDs</button>
    </div>

    <div class="mt-4 flex flex-wrap gap-5">
        <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" class="h-4 w-4 rounded accent-brand-600" data-uppercase>
            Uppercase
        </label>
        <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" class="h-4 w-4 rounded accent-brand-600" data-nodashes>
            Remove dashes
        </label>
    </div>

    <p class="mt-4 min-h-5 text-sm text-slate-500" data-status aria-live="polite"></p>

    <div class="mt-3 flex items-center justify-between">
        <p class="text-sm font-semibold text-slate-900">Generated UUIDs (version 4)</p>
        <button type="button" class="btn-secondary hidden" data-copy-all>Copy all</button>
    </div>

    <ul class="mt-3 space-y-2" data-list></ul>
    <p class="mt-3 text-sm text-slate-500">Press “Generate UUIDs” to create cryptographically random v4 identifiers.</p>
</div>
@endsection
