@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}">
    <div class="dropzone cursor-pointer" data-dropzone>
        <x-icon name="image" class="h-8 w-8 text-brand-500"/>
        <p class="font-medium text-slate-700">Drop a JPG image here, or</p>
        <button type="button" class="btn-primary" data-browse>Choose JPG</button>
        <p class="text-xs text-slate-500">JPG only · up to 15 MB · converted locally in your browser</p>
        <input type="file" class="hidden" accept="image/jpeg" data-file-input>
    </div>

    <p class="mt-3 min-h-5 text-sm text-slate-500" data-status aria-live="polite"></p>
    <div data-results></div>
</div>
@endsection
