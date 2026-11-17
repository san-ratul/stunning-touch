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

    <div hidden data-controls class="mt-5 space-y-3 border-t border-slate-100 pt-4">
        <p class="text-xs text-slate-500">
            Original <span class="font-medium text-slate-700" data-original-dims></span>
            <span class="mx-1 text-slate-300">·</span>
            Crop area <span class="font-medium text-slate-700" data-crop-dims-label></span>
            <span class="mx-1 text-slate-300">·</span>
            Output <span class="font-medium text-slate-700" data-output-dims-label></span>
        </p>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_300px] lg:items-start">
            <div class="min-w-0">
                <div data-crop-mount></div>
                <p class="mt-2 text-xs text-slate-500">Drag the box or its handles to pick the area — arrow keys nudge too.</p>
            </div>

            <div class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="w">Width (px)</label>
                        <input id="w" type="number" min="1" max="12000" class="input" data-w>
                    </div>
                    <div>
                        <label class="label" for="h">Height (px)</label>
                        <input id="h" type="number" min="1" max="12000" class="input" data-h>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="pct">Resize by %</label>
                        <input id="pct" type="number" min="1" max="400" class="input" placeholder="e.g. 50" data-percent>
                    </div>
                    <div>
                        <label class="label" for="preset">Presets</label>
                        <select id="preset" class="input" data-presets></select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="rformat">Format</label>
                        <select id="rformat" class="input" data-format>
                            <option value="image/jpeg">JPEG</option>
                            <option value="image/png">PNG</option>
                            <option value="image/webp">WebP</option>
                        </select>
                    </div>
                    <div class="flex items-end pb-3">
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" checked class="h-4 w-4 rounded accent-brand-600" data-lock>
                            Keep aspect ratio
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-[auto_1fr] items-start gap-4 border-t border-slate-100 pt-3">
                    <div>
                        <span class="label mb-1.5 block">Crop position</span>
                        <div class="grid w-max grid-cols-3 gap-1" data-position-presets role="group" aria-label="Crop position presets">
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
                    <div class="flex flex-col items-start gap-2">
                        <div>
                            <span class="label mb-1.5 block">Crop preview</span>
                            <canvas data-crop-preview width="220" height="220" class="max-h-[110px] max-w-[110px] rounded-lg border border-slate-200 bg-slate-100" aria-label="Preview of the selected crop area" role="img"></canvas>
                        </div>
                        <button type="button" class="btn-secondary px-3 py-1.5 text-xs" data-reset-crop>Reset crop</button>
                    </div>
                </div>

                <button type="button" class="btn-accent btn-lg w-full" data-apply>Crop &amp; Resize</button>
            </div>
        </div>

        <p class="-mt-1 text-xs text-slate-500">
            Width and height set the output size — when they exceed the image, the selected area is enlarged to fit.
        </p>
    </div>

    <div data-results></div>
</div>
@endsection
