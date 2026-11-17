/**
 * Shared behavior for the four image tools: dropzone handling, file
 * validation, image loading and result card rendering. All processing
 * happens locally in the browser — files never leave the device.
 */
import { trackToolUsage } from '../analytics';
import { baseName, canvasToBlob, downloadBlob, formatBytes, readFileAsImage } from './helpers';

export function createImageToolUI(el, options) {
    const dropzone = el.querySelector('[data-dropzone]');
    const fileInput = el.querySelector('[data-file-input]');
    const browseBtn = el.querySelector('[data-browse]');
    const results = el.querySelector('[data-results]');
    const statusEl = el.querySelector('[data-status]');

    const maxBytes = 15 * 1024 * 1024;

    function setStatus(message, isError = false) {
        if (!statusEl) return;
        statusEl.textContent = message || '';
        statusEl.classList.toggle('text-red-600', isError);
        statusEl.classList.toggle('text-slate-500', !isError);
    }

    function acceptFiles(files) {
        const file = files && files[0];
        if (!file) return;

        if (!file.type.startsWith('image/')) {
            setStatus('Please choose an image file.', true);
            return;
        }
        if (file.size > maxBytes) {
            setStatus(`File is too large. Maximum size is ${formatBytes(maxBytes)}.`, true);
            return;
        }

        readFileAsImage(file)
            .then((img) => {
                setStatus('');
                options.onImage(img, file);
            })
            .catch(() => setStatus('This image could not be read. Try a different file.', true));
    }

    dropzone?.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropzone.classList.add('is-dragover');
    });
    dropzone?.addEventListener('dragleave', () => dropzone.classList.remove('is-dragover'));
    dropzone?.addEventListener('drop', (e) => {
        e.preventDefault();
        dropzone.classList.remove('is-dragover');
        acceptFiles(e.dataTransfer.files);
    });
    browseBtn?.addEventListener('click', () => fileInput?.click());
    dropzone?.addEventListener('click', () => fileInput?.click());
    fileInput?.addEventListener('change', () => acceptFiles(fileInput.files));

    function renderResult({ blob, filename, originalSize, extraLines = [], previewUrl }) {
        const url = previewUrl ?? URL.createObjectURL(blob);
        const savings = originalSize ? Math.max(0, Math.round((1 - blob.size / originalSize) * 100)) : null;

        const card = document.createElement('div');
        card.className = 'card mt-6 overflow-hidden';
        card.innerHTML = `
            <div class="grid gap-4 p-5 sm:grid-cols-[180px_1fr]">
                <img src="${url}" alt="Result preview" class="h-36 w-full rounded-xl border border-slate-200 bg-slate-100 object-contain sm:h-40" loading="lazy">
                <div class="flex flex-col justify-center gap-2">
                    <p class="font-semibold text-slate-900">${filename}</p>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm text-slate-600">
                        <dt>Original</dt><dd class="font-medium text-slate-900">${formatBytes(originalSize)}</dd>
                        <dt>New</dt><dd class="font-medium text-slate-900">${formatBytes(blob.size)}</dd>
                        ${extraLines.map(([k, v]) => `<dt>${k}</dt><dd class="font-medium text-slate-900">${v}</dd>`).join('')}
                        ${savings !== null ? `<dt>Saved</dt><dd class="font-medium ${savings > 0 ? 'text-emerald-600' : 'text-slate-900'}">${savings}%</dd>` : ''}
                    </dl>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" class="btn-primary" data-download>Download</button>
                    </div>
                </div>
            </div>`;

        card.querySelector('[data-download]').addEventListener('click', () => {
            downloadBlob(blob, filename);
            trackToolUsage(el.dataset.tool, true, 'download');
        });

        results.innerHTML = '';
        results.appendChild(card);
    }

    function reportError(message) {
        setStatus(message, true);
        trackToolUsage(el.dataset.tool, false);
    }

    function reportSuccess() {
        trackToolUsage(el.dataset.tool, true);
    }

    return { setStatus, renderResult, reportError, reportSuccess };
}

export function scaleTo(canvasSource, targetW, targetH) {
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(targetW));
    canvas.height = Math.max(1, Math.round(targetH));
    canvas.getContext('2d').drawImage(canvasSource, 0, 0, canvas.width, canvas.height);
    return canvas;
}

/**
 * Draw the `cropRect` region ({x, y, w, h} in source pixels) of `source`
 * onto a canvas of `outW` × `outH` pixels. When out dims equal the crop
 * dims this is a lossless 1:1 crop; otherwise the cropped region is
 * scaled to the requested output size.
 */
export function cropTo(canvasSource, cropRect, outW, outH) {
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(outW));
    canvas.height = Math.max(1, Math.round(outH));
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(
        canvasSource,
        cropRect.x, cropRect.y, cropRect.w, cropRect.h,
        0, 0, canvas.width, canvas.height,
    );
    return canvas;
}

export { baseName, canvasToBlob };
