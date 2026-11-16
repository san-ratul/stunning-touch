@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}">
    <div class="dropzone cursor-pointer" data-dropzone>
        <x-icon name="upload" class="h-8 w-8 text-brand-500"/>
        <p class="font-medium text-slate-700">Drag &amp; drop an image here, or</p>
        <button type="button" class="btn-primary" data-browse>Choose Image</button>
        <p class="text-xs text-slate-500">JPG, PNG or WebP · up to 15 MB · processed locally, never uploaded</p>
        <input type="file" class="hidden" accept="image/jpeg,image/png,image/webp" data-file-input>
    </div>

    <p class="mt-3 min-h-5 text-sm text-slate-500" data-status aria-live="polite"></p>

    <div hidden data-controls class="mt-5 space-y-4 border-t border-slate-100 pt-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="quality">Quality: <span data-quality-label>75%</span></label>
                <input id="quality" type="range" min="10" max="95" value="75" class="w-full accent-brand-600" data-quality>
            </div>
            <div>
                <label class="label" for="format">Output format</label>
                <select id="format" class="input" data-format>
                    <option value="image/jpeg">JPEG (smaller)</option>
                    <option value="image/webp">WebP (smallest)</option>
                    <option value="image/png">PNG (lossless)</option>
                </select>
            </div>
        </div>
    </div>

    <div data-results></div>
</div>
@endsection
