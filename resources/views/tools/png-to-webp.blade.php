@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}">
    <div class="dropzone cursor-pointer" data-dropzone>
        <x-icon name="image" class="h-8 w-8 text-brand-500"/>
        <p class="font-medium text-slate-700">Drop a PNG image here, or</p>
        <button type="button" class="btn-primary" data-browse>Choose PNG</button>
        <p class="text-xs text-slate-500">PNG only · up to 15 MB · converted locally in your browser</p>
        <input type="file" class="hidden" accept="image/png" data-file-input>
    </div>

    <p class="mt-3 min-h-5 text-sm text-slate-500" data-status aria-live="polite"></p>

    <div hidden data-controls class="mt-5 border-t border-slate-100 pt-5">
        <label class="label" for="wq">WebP quality: <span data-quality-label>80%</span></label>
        <input id="wq" type="range" min="10" max="100" value="80" class="w-full max-w-md accent-brand-600" data-quality>
    </div>

    <div data-results></div>
</div>
@endsection
