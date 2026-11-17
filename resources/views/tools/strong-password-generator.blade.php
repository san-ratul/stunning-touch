@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}">

    {{-- Length --}}
    <div>
        <div class="flex items-baseline justify-between gap-3">
            <label class="label" for="pwd-length-range">Password length</label>
            <span class="text-sm font-bold tabular-nums text-brand-700" data-length-info>{{ $length ?? 24 }}</span>
        </div>
        <div class="mt-2 flex items-center gap-4">
            <input type="range" id="pwd-length-range" class="w-full accent-brand-600" min="8" max="128" step="1" value="24" data-length-range aria-label="Password length slider">
            <input type="number" id="pwd-length-number" class="input w-24 text-center" min="8" max="128" step="1" value="24" data-length-number aria-label="Password length">
        </div>
    </div>

    {{-- Character options --}}
    <fieldset class="mt-6">
        <legend class="label">Character groups</legend>
        <div class="mt-2 grid gap-2 sm:grid-cols-2">
            <label class="inline-flex cursor-pointer items-center gap-2.5 rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-2.5 text-sm font-medium text-slate-700">
                <input type="checkbox" class="h-4 w-4 accent-brand-600" data-group="upper" checked> Uppercase (A–Z)
            </label>
            <label class="inline-flex cursor-pointer items-center gap-2.5 rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-2.5 text-sm font-medium text-slate-700">
                <input type="checkbox" class="h-4 w-4 accent-brand-600" data-group="lower" checked> Lowercase (a–z)
            </label>
            <label class="inline-flex cursor-pointer items-center gap-2.5 rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-2.5 text-sm font-medium text-slate-700">
                <input type="checkbox" class="h-4 w-4 accent-brand-600" data-group="number" checked> Numbers (0–9)
            </label>
            <label class="inline-flex cursor-pointer items-center gap-2.5 rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-2.5 text-sm font-medium text-slate-700">
                <input type="checkbox" class="h-4 w-4 accent-brand-600" data-group="symbol" checked> Symbols (!@#$…)
            </label>
        </div>

        <label class="mt-3 inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
            <input type="checkbox" class="h-4 w-4 accent-brand-600" data-exclude-ambiguous>
            Exclude ambiguous characters (0, O, l, I)
        </label>
    </fieldset>

    {{-- Quantity --}}
    <div class="mt-5 max-w-xs">
        <label class="label" for="pwd-count">How many passwords</label>
        <select id="pwd-count" class="input" data-count>
            <option value="1" selected>1 password</option>
            <option value="5">5 passwords</option>
            <option value="10">10 passwords</option>
        </select>
    </div>

    {{-- Error / empty states --}}
    <div class="mt-5 hidden rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700" role="alert" data-error>
        Select at least one character group to generate a password.
    </div>
    <div class="mt-8 rounded-xl border border-dashed border-slate-300 px-6 py-10 text-center" data-empty>
        <p class="font-medium text-slate-600">Click “Generate” to create a password</p>
        <p class="mt-1 text-sm text-slate-500">Generated with your browser's cryptographic random generator. Passwords never leave your device and are never stored.</p>
    </div>

    {{-- Output --}}
    <div class="mt-6 space-y-2" data-output hidden></div>

    {{-- Actions --}}
    <div class="mt-6 flex flex-wrap gap-2">
        <button type="button" class="btn-primary" data-generate>Generate</button>
        <button type="button" class="btn-secondary" data-copy-all hidden>Copy all</button>
        <button type="button" class="btn-danger" data-clear>Clear</button>
    </div>

    {{-- Strength --}}
    <div class="mt-8" data-strength>
        <div class="flex items-baseline justify-between gap-3">
            <p class="text-sm font-semibold text-slate-900">Strength: <span class="text-brand-700" data-strength-label>—</span></p>
            <p class="text-xs text-slate-500">Estimated, not a guarantee of unbreakability.</p>
        </div>
        <div class="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-slate-200">
            <div class="h-2.5 rounded-full bg-brand-600 transition-all duration-300" style="width: 0%" data-strength-bar></div>
        </div>
        <dl class="mt-3 grid grid-cols-3 gap-3 text-center">
            <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Length</dt>
                <dd class="mt-0.5 text-lg font-extrabold tabular-nums text-slate-800" data-length-info-secondary>{{ $length ?? 24 }}</dd>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Character pool</dt>
                <dd class="mt-0.5 text-lg font-extrabold tabular-nums text-slate-800" data-pool>—</dd>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Estimated entropy</dt>
                <dd class="mt-0.5 text-lg font-extrabold tabular-nums text-slate-800" data-entropy>—</dd>
            </div>
        </dl>
    </div>
</div>
@endsection
