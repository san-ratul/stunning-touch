@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}">
    <div class="dropzone cursor-pointer" data-dropzone>
        <x-icon name="pdf" class="h-8 w-8 text-brand-500"/>
        <p class="font-medium text-slate-700">Drag &amp; drop a PDF here, or</p>
        <button type="button" class="btn-primary" data-browse>Choose PDF File</button>
        <p class="text-xs text-slate-500">Single PDF · up to 40 MB · processed locally</p>
        <input type="file" class="hidden" accept="application/pdf" data-file-input>
    </div>

    <p class="mt-3 min-h-5 text-sm text-slate-500" data-status aria-live="polite"></p>

    <div hidden data-file-info class="mt-5 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-800" data-file-text></div>

    <div hidden class="mt-5 space-y-5 border-t border-slate-100 pt-5" x-data="{ mode: 'range' }">
        <div class="flex gap-3" role="radiogroup" aria-label="Split mode">
            <label class="flex flex-1 cursor-pointer items-center gap-2.5 rounded-xl border px-4 py-3 text-sm font-medium transition-colors"
                   :class="mode === 'range' ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-slate-200 text-slate-600 hover:border-slate-300'">
                <input type="radio" name="split-mode" value="range" checked class="accent-brand-600" data-mode-range x-model="mode">
                Page range
            </label>
            <label class="flex flex-1 cursor-pointer items-center gap-2.5 rounded-xl border px-4 py-3 text-sm font-medium transition-colors"
                   :class="mode === 'extract' ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-slate-200 text-slate-600 hover:border-slate-300'">
                <input type="radio" name="split-mode" value="extract" class="accent-brand-600" data-mode-extract x-model="mode">
                Custom pages
            </label>
        </div>

        <div x-show="mode === 'range'" class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="from">From page</label>
                <input id="from" type="number" min="1" value="1" class="input" data-from>
            </div>
            <div>
                <label class="label" for="to">To page</label>
                <input id="to" type="number" min="1" value="1" class="input" data-to>
            </div>
        </div>

        <div x-show="mode === 'extract'" x-cloak>
            <label class="label" for="pages">Pages to extract</label>
            <input id="pages" type="text" class="input" placeholder="e.g. 1, 3, 5-8" data-pages>
            <p class="mt-1.5 text-xs text-slate-500">Separate pages and ranges with commas.</p>
        </div>

        <button type="button" class="btn-accent btn-lg w-full sm:w-auto" data-split>Extract Pages</button>
    </div>
</div>
@endsection
