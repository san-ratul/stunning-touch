@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}">
    <div class="grid gap-6 lg:grid-cols-2">
        <div>
            <label class="label" for="jf-input">JSON input</label>
            <textarea id="jf-input" rows="14" class="input resize-y font-mono text-[13px] scroll-thin" placeholder='{"name": "Stunning Touch", "free": true}' data-input spellcheck="false"></textarea>
        </div>
        <div>
            <label class="label" for="jf-output">Formatted output</label>
            <textarea id="jf-output" rows="14" class="input resize-y bg-brand-50/40 font-mono text-[13px] scroll-thin" readonly data-output spellcheck="false"></textarea>
        </div>
    </div>

    <p class="mt-3 min-h-5 font-medium" data-status aria-live="polite"></p>

    <div class="mt-2 flex flex-wrap gap-2">
        <button type="button" class="btn-primary" data-format>Format</button>
        <button type="button" class="btn-secondary" data-minify>Minify</button>
        <button type="button" class="btn-secondary" data-validate>Validate</button>
        <button type="button" class="btn-secondary" data-copy>Copy</button>
        <button type="button" class="btn-danger" data-clear>Clear</button>
    </div>

    <div class="mt-6 grid grid-cols-2 gap-3 sm:max-w-xs" data-stats hidden>
        <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-center">
            <p class="text-2xl font-extrabold tabular-nums text-brand-700" data-stat-keys>0</p>
            <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">Keys & nodes</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-center">
            <p class="text-2xl font-extrabold tabular-nums text-brand-700" data-stat-depth>0</p>
            <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">Max depth</p>
        </div>
    </div>
</div>
@endsection
