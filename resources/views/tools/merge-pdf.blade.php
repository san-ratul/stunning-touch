@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}">
    <div class="dropzone cursor-pointer" data-dropzone>
        <x-icon name="pdf" class="h-8 w-8 text-brand-500"/>
        <p class="font-medium text-slate-700">Drag &amp; drop PDF files here, or</p>
        <button type="button" class="btn-primary" data-browse>Choose PDF Files</button>
        <p class="text-xs text-slate-500">Multiple PDFs · up to 40 MB total per file · merged locally</p>
        <input type="file" class="hidden" accept="application/pdf" multiple data-file-input>
    </div>

    <p class="mt-3 min-h-5 text-sm text-slate-500" data-status aria-live="polite"></p>

    <div class="mt-5" hidden>
        <ol class="space-y-2" data-list></ol>
        <button type="button" class="btn-accent btn-lg mt-5 w-full" data-merge disabled>Merge PDFs</button>
    </div>
    <div class="mt-5" data-results hidden>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
            ✓ Merged PDF downloaded. Check your downloads folder.
        </div>
    </div>
</div>
@endsection
