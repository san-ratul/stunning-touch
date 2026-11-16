@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}">
    <div class="dropzone cursor-pointer" data-dropzone>
        <x-icon name="upload" class="h-8 w-8 text-brand-500"/>
        <p class="font-medium text-slate-700">Drag &amp; drop an image here, or</p>
        <button type="button" class="btn-primary" data-browse>Choose Image</button>
        <p class="text-xs text-slate-500">JPG, PNG or WebP · up to 15 MB · processed locally</p>
        <input type="file" class="hidden" accept="image/jpeg,image/png,image/webp" data-file-input>
    </div>

    <p class="mt-3 min-h-5 text-sm text-slate-500" data-status aria-live="polite"></p>

    <div hidden data-controls class="mt-5 space-y-5 border-t border-slate-100 pt-5">
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="label" for="w">Width (px)</label>
                <input id="w" type="number" min="1" max="12000" class="input" data-w>
            </div>
            <div>
                <label class="label" for="h">Height (px)</label>
                <input id="h" type="number" min="1" max="12000" class="input" data-h>
            </div>
            <div>
                <label class="label" for="pct">Resize by %</label>
                <input id="pct" type="number" min="1" max="400" class="input" placeholder="e.g. 50" data-percent>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="label" for="preset">Presets</label>
                <select id="preset" class="input" data-presets></select>
            </div>
            <div>
                <label class="label" for="rformat">Output format</label>
                <select id="rformat" class="input" data-format>
                    <option value="image/jpeg">JPEG</option>
                    <option value="image/png">PNG</option>
                    <option value="image/webp">WebP</option>
                </select>
            </div>
            <div class="flex items-end">
                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700">
                    <input type="checkbox" checked class="h-4 w-4 rounded accent-brand-600" data-lock>
                    Lock aspect ratio
                </label>
            </div>
        </div>

        <button type="button" class="btn-accent btn-lg w-full sm:w-auto" data-apply>Resize Image</button>
    </div>

    <div data-results></div>
</div>
@endsection
