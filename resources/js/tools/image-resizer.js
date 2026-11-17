/**
 * Image Resizer with the integrated interactive crop editor.
 *
 * Model (kept explicit so crop and resize are never confused):
 *   - Original image dimensions: read once from the uploaded image.
 *   - The Width/Height inputs are the source of truth for the requested
 *     output dimensions AND drive the crop box (clamped into the image).
 *   - The crop box shows the exact exported region in original-image
 *     pixels; dragging/resizing it writes its dimensions back to the
 *     inputs (1:1 output). If the requested size exceeds the image, the
 *     box clamps to the image bounds and the export upscales the region.
 *
 * All processing stays local in the browser — files never leave the
 * device — exactly like the other image tools.
 */
import { createImageToolUI, cropTo, canvasToBlob } from './image-tool';
import { createCropEditor } from './crop-editor';
import {
    MIN_CROP_DIM,
    cropFromRequested,
    heightFromWidth,
    matchPreset,
    widthFromHeight,
} from './crop-core';
import { baseName } from './helpers';

const PRESETS = [
    { label: 'HD 1280×720', w: 1280, h: 720 },
    { label: 'Full HD 1920×1080', w: 1920, h: 1080 },
    { label: 'Social 1080×1080', w: 1080, h: 1080 },
    { label: 'Story 1080×1920', w: 1080, h: 1920 },
];

const MAX_OUTPUT_DIM = 12000;

export function init(el) {
    let current = null;   // { img, file }
    let editor = null;    // crop editor instance
    let ratio = null;     // locked aspect ratio (w/h), null = free
    let syncing = false;  // true while inputs drive the crop box

    const wInput = el.querySelector('[data-w]');
    const hInput = el.querySelector('[data-h]');
    const lockRatio = el.querySelector('[data-lock]');
    const percentInput = el.querySelector('[data-percent]');
    const presetSelect = el.querySelector('[data-presets]');
    const formatSelect = el.querySelector('[data-format]');
    const applyBtn = el.querySelector('[data-apply]');
    const controls = el.querySelector('[data-controls]');
    const cropMount = el.querySelector('[data-crop-mount]');
    const originalDims = el.querySelector('[data-original-dims]');
    const cropDimsLabel = el.querySelector('[data-crop-dims-label]');
    const outputDimsLabel = el.querySelector('[data-output-dims-label]');
    const positionGroup = el.querySelector('[data-position-presets]');
    const positionButtons = positionGroup
        ? Array.from(positionGroup.querySelectorAll('[data-pos]'))
        : [];
    const resetBtn = el.querySelector('[data-reset-crop]');
    const previewCanvas = el.querySelector('[data-crop-preview]');

    presetSelect.innerHTML = '<option value="">Custom size…</option>' +
        PRESETS.map((p, i) => `<option value="${i}">${p.label}</option>`).join('');

    const ui = createImageToolUI(el, {
        onImage(img, file) {
            current = { img, file };
            ratio = null;
            wInput.value = img.naturalWidth;
            hInput.value = img.naturalHeight;
            if (originalDims) originalDims.textContent = `${img.naturalWidth} × ${img.naturalHeight} px`;

            cropMount.replaceChildren(); // drop any editor from a previous image
            editor = createCropEditor(cropMount, img, { onChange: onCropChange });
            if (lockRatio.checked) ratio = img.naturalWidth / img.naturalHeight;
            editor.setRatio(ratio);

            controls.hidden = false;
            percentInput.value = '';
            presetSelect.value = '';
            onCropChange(editor.getCrop());
            ui.setStatus('');
        },
    });

    // ---- Crop editor <-> input synchronization ----

    function onCropChange(cropRect) {
        if (!current) return;

        if (!syncing) {
            // The box was changed visually: its dimensions (1:1) become
            // the requested output dimensions.
            wInput.value = cropRect.w;
            hInput.value = cropRect.h;
        }

        cropDimsLabel.textContent = `${cropRect.w} × ${cropRect.h} px`;
        updatePositionButtons(cropRect);
        drawPreview(cropRect);
        updateOutputLabel();
    }

    function updateOutputLabel() {
        if (!outputDimsLabel) return;
        outputDimsLabel.textContent = `${wInput.value || '—'} × ${hInput.value || '—'} px`;
    }

    function updatePositionButtons(cropRect) {
        const active = matchPreset(cropRect, current.img.naturalWidth, current.img.naturalHeight);
        for (const btn of positionButtons) {
            const isActive = btn.dataset.pos === active;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        }
    }

    let previewFrame = 0;

    function drawPreview(cropRect) {
        if (!previewCanvas || !current) return;
        cancelAnimationFrame(previewFrame);
        previewFrame = requestAnimationFrame(() => {
            if (!current) return;
            const maxSide = 320;
            const scale = Math.min(1, maxSide / cropRect.w, maxSide / cropRect.h);
            previewCanvas.width = Math.max(1, Math.round(cropRect.w * scale));
            previewCanvas.height = Math.max(1, Math.round(cropRect.h * scale));
            const ctx = previewCanvas.getContext('2d');
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(
                current.img,
                cropRect.x, cropRect.y, cropRect.w, cropRect.h,
                0, 0, previewCanvas.width, previewCanvas.height,
            );
        });
    }

    /**
     * Push the requested width/height from the inputs into the crop box.
     * `source` names the field the user typed in so the ratio derives the
     * other field. The field being typed is never rewritten (no fighting
     * the keyboard); it is normalized on blur instead.
     */
    function syncInputsToCrop(source) {
        if (!current || !editor || syncing) return;

        const imgW = current.img.naturalWidth;
        const imgH = current.img.naturalHeight;
        let w = Number.parseInt(wInput.value, 10);
        let h = Number.parseInt(hInput.value, 10);
        if (!Number.isFinite(w) || w < 1 || !Number.isFinite(h) || h < 1) return;

        if (ratio !== null && source === 'w') {
            h = heightFromWidth(w, ratio);
            hInput.value = h;
        } else if (ratio !== null && source === 'h') {
            w = widthFromHeight(h, ratio);
            wInput.value = w;
        }

        syncing = true;
        editor.setCrop(cropFromRequested(editor.getCrop(), w, h, imgW, imgH), 'inputs');
        syncing = false;

        updateOutputLabel();
    }

    function normalizeInputsOnBlur() {
        if (!current || !editor) return;
        const cropRect = editor.getCrop();
        if (!Number.isFinite(Number.parseInt(wInput.value, 10)) || Number.parseInt(wInput.value, 10) < 1) {
            wInput.value = cropRect.w;
        }
        if (!Number.isFinite(Number.parseInt(hInput.value, 10)) || Number.parseInt(hInput.value, 10) < 1) {
            hInput.value = cropRect.h;
        }
        updateOutputLabel();
    }

    wInput.addEventListener('input', () => {
        if (!current) return;
        presetSelect.value = '';
        percentInput.value = '';
        syncInputsToCrop('w');
    });
    hInput.addEventListener('input', () => {
        if (!current) return;
        presetSelect.value = '';
        percentInput.value = '';
        syncInputsToCrop('h');
    });
    wInput.addEventListener('change', normalizeInputsOnBlur);
    hInput.addEventListener('change', normalizeInputsOnBlur);

    // ---- Keep aspect ratio ----

    lockRatio.addEventListener('change', () => {
        if (!current || !editor) return;
        // Enabling the lock captures the CURRENT crop ratio, so whatever
        // the user already dialed in is preserved from now on.
        const cropRect = editor.getCrop();
        ratio = lockRatio.checked ? cropRect.w / cropRect.h : null;
        editor.setRatio(ratio);
    });

    // ---- Size presets / percent (existing behavior) ----

    presetSelect.onchange = () => {
        if (!current || presetSelect.value === '') return;
        const preset = PRESETS[Number(presetSelect.value)];
        const imgRatio = current.img.naturalWidth / current.img.naturalHeight;
        const fitW = Math.min(preset.w, preset.h * imgRatio);
        const fitH = fitW / imgRatio;
        wInput.value = Math.round(fitW);
        hInput.value = Math.round(fitH);
        percentInput.value = '';
        syncInputsToCrop(null);
    };

    percentInput.oninput = () => {
        if (!current) return;
        const pct = Number(percentInput.value);
        if (!pct || pct < 1 || pct > 400) return;
        wInput.value = Math.round((current.img.naturalWidth * pct) / 100);
        hInput.value = Math.round((current.img.naturalHeight * pct) / 100);
        presetSelect.value = '';
        syncInputsToCrop(null);
    };

    // ---- Crop position presets ----

    for (const btn of positionButtons) {
        btn.addEventListener('click', () => {
            if (!current || !editor) return;
            editor.applyPreset(btn.dataset.pos);
        });
    }

    // ---- Reset crop ----

    resetBtn.addEventListener('click', () => {
        if (!current || !editor) return;
        editor.reset();
        wInput.value = current.img.naturalWidth;
        hInput.value = current.img.naturalHeight;
        ratio = lockRatio.checked ? current.img.naturalWidth / current.img.naturalHeight : null;
        editor.setRatio(ratio);
        percentInput.value = '';
        presetSelect.value = '';
        updateOutputLabel();
        ui.setStatus('');
    });

    // ---- Process: crop, then resize to the requested dimensions ----

    applyBtn.addEventListener('click', async () => {
        if (!current || !editor) {
            ui.setStatus('Choose an image first.', true);
            return;
        }

        const img = current.img;
        const w = Number(wInput.value);
        const h = Number(hInput.value);
        const minW = Math.min(MIN_CROP_DIM, img.naturalWidth);
        const minH = Math.min(MIN_CROP_DIM, img.naturalHeight);

        if (!Number.isFinite(w) || !Number.isFinite(h) || w < minW || h < minH || w > MAX_OUTPUT_DIM || h > MAX_OUTPUT_DIM) {
            ui.setStatus(
                `Enter a width between ${minW} and ${MAX_OUTPUT_DIM} px and a height between ${minH} and ${MAX_OUTPUT_DIM} px.`,
                true,
            );
            return;
        }

        // Defense in depth: the crop rect is always validated against the
        // original image bounds immediately before drawing. Nothing is
        // trusted blindly, even though it never leaves the browser.
        const cropRect = editor.getCrop();
        cropRect.w = Math.min(cropRect.w, img.naturalWidth);
        cropRect.h = Math.min(cropRect.h, img.naturalHeight);
        cropRect.x = Math.min(Math.max(0, cropRect.x), img.naturalWidth - cropRect.w);
        cropRect.y = Math.min(Math.max(0, cropRect.y), img.naturalHeight - cropRect.h);

        try {
            const canvas = cropTo(img, cropRect, w, h);
            const type = formatSelect.value;
            const quality = type === 'image/png' ? undefined : 0.92;
            const blob = await canvasToBlob(canvas, type, quality);
            const ext = type === 'image/webp' ? 'webp' : (type === 'image/png' ? 'png' : 'jpg');

            ui.renderResult({
                blob,
                filename: `${baseName(current.file.name)}-${w}x${h}.${ext}`,
                originalSize: current.file.size,
                extraLines: [
                    ['Dimensions', `${w} × ${h} px`],
                    ['Crop area', `${cropRect.w} × ${cropRect.h} px at ${cropRect.x}, ${cropRect.y}`],
                ],
            });
            ui.reportSuccess();
        } catch (err) {
            ui.reportError(err.message);
        }
    });
}
