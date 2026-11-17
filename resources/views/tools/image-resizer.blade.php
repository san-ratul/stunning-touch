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
        <p class="text-sm text-slate-500">
            Original image: <span class="font-medium text-slate-700" data-original-dims></span>
            <span class="mx-1.5 text-slate-300">|</span>
            Selected crop area: <span class="font-medium text-slate-700" data-crop-dims-label></span>
            <span class="mx-1.5 text-slate-300">|</span>
            Output size: <span class="font-medium text-slate-700" data-output-dims-label></span>
        </p>

        <div data-crop-mount></div>

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
                    Keep aspect ratio
                </label>
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-[auto_1fr] sm:items-start">
            <div>
                <span class="label mb-1.5 block">Crop position</span>
                <div class="grid w-max grid-cols-3 gap-1.5" data-position-presets role="group" aria-label="Crop position presets">
                    <button type="button" class="crop-pos-btn" data-pos="top-left" aria-pressed="false" title="Top left" aria-label="Position crop at top left"><span class="crop-pos-dot"></span></button>
                    <button type="button" class="crop-pos-btn" data-pos="top" aria-pressed="false" title="Top" aria-label="Position crop at top"><span class="crop-pos-dot"></span></button>
                    <button type="button" class="crop-pos-btn" data-pos="top-right" aria-pressed="false" title="Top right" aria-label="Position crop at top right"><span class="crop-pos-dot"></span></button>
                    <button type="button" class="crop-pos-btn" data-pos="left" aria-pressed="false" title="Left" aria-label="Position crop at left"><span class="crop-pos-dot"></span></button>
                    <button type="button" class="crop-pos-btn" data-pos="center" aria-pressed="false" title="Center" aria-label="Position crop at center"><span class="crop-pos-dot"></span></button>
                    <button type="button" class="crop-pos-btn" data-pos="right" aria-pressed="false" title="Right" aria-label="Position crop at right"><span class="crop-pos-dot"></span></button>
                    <button type="button" class="crop-pos-btn" data-pos="bottom-left" aria-pressed="false" title="Bottom left" aria-label="Position crop at bottom left"><span class="crop-pos-dot"></span></button>
                    <button type="button" class="crop-pos-btn" data-pos="bottom" aria-pressed="false" title="Bottom" aria-label="Position crop at bottom"><span class="crop-pos-dot"></span></button>
                    <button type="button" class="crop-pos-btn" data-pos="bottom-right" aria-pressed="false" title="Bottom right" aria-label="Position crop at bottom right"><span class="crop-pos-dot"></span></button>
                </div>
            </div>
            <div class="flex flex-wrap items-start gap-4">
                <div>
                    <span class="label mb-1.5 block">Crop preview</span>
                    <canvas data-crop-preview width="160" height="120" class="max-w-[160px] rounded-lg border border-slate-200 bg-slate-100" aria-label="Preview of the selected crop area" role="img"></canvas>
                </div>
                <div class="flex items-end pt-0 sm:pt-6">
                    <button type="button" class="btn-secondary" data-reset-crop>Reset crop</button>
                </div>
            </div>
        </div>

        <p class="text-xs text-slate-500">
            Drag the crop box or its handles to select the area. Width and height set the output size —
            when they exceed the image, the selected area is enlarged to fit.
        </p>

        <button type="button" class="btn-accent btn-lg w-full sm:w-auto" data-apply>Crop &amp; Resize</button>
    </div>

    <div data-results></div>
</div>
@endsection
