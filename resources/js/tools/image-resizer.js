import { createImageToolUI, scaleTo, canvasToBlob } from './image-tool';
import { baseName } from './helpers';

const PRESETS = [
    { label: 'HD 1280×720', w: 1280, h: 720 },
    { label: 'Full HD 1920×1080', w: 1920, h: 1080 },
    { label: 'Social 1080×1080', w: 1080, h: 1080 },
    { label: 'Story 1080×1920', w: 1080, h: 1920 },
];

export function init(el) {
    let current = null;

    const wInput = el.querySelector('[data-w]');
    const hInput = el.querySelector('[data-h]');
    const lockRatio = el.querySelector('[data-lock]');
    const percentInput = el.querySelector('[data-percent]');
    const presetSelect = el.querySelector('[data-presets]');
    const formatSelect = el.querySelector('[data-format]');
    const applyBtn = el.querySelector('[data-apply]');
    const controls = el.querySelector('[data-controls]');

    presetSelect.innerHTML = '<option value="">Custom size…</option>' +
        PRESETS.map((p, i) => `<option value="${i}">${p.label}</option>`).join('');

    const ui = createImageToolUI(el, {
        onImage(img, file) {
            current = { img, file };
            wInput.value = img.naturalWidth;
            hInput.value = img.naturalHeight;
            controls.hidden = false;
            ui.setStatus('');
        },
    });

    presetSelect.onchange = () => {
        if (!current || presetSelect.value === '') return;
        const preset = PRESETS[Number(presetSelect.value)];
        const ratio = current.img.naturalWidth / current.img.naturalHeight;
        const fitW = Math.min(preset.w, preset.h * ratio);
        const fitH = fitW / ratio;
        wInput.value = Math.round(fitW);
        hInput.value = Math.round(fitH);
        percentInput.value = '';
    };

    percentInput.oninput = () => {
        if (!current) return;
        const pct = Number(percentInput.value);
        if (!pct || pct < 1 || pct > 400) return;
        wInput.value = Math.round((current.img.naturalWidth * pct) / 100);
        hInput.value = Math.round((current.img.naturalHeight * pct) / 100);
        presetSelect.value = '';
    };

    wInput.oninput = () => {
        if (!current || !lockRatio.checked) return;
        const ratio = current.img.naturalWidth / current.img.naturalHeight;
        hInput.value = Math.max(1, Math.round(Number(wInput.value) / ratio));
        presetSelect.value = '';
        percentInput.value = '';
    };

    hInput.oninput = () => {
        if (!current || !lockRatio.checked) return;
        const ratio = current.img.naturalWidth / current.img.naturalHeight;
        wInput.value = Math.max(1, Math.round(Number(hInput.value) * ratio));
        presetSelect.value = '';
        percentInput.value = '';
    };

    applyBtn.addEventListener('click', async () => {
        if (!current) {
            ui.setStatus('Choose an image first.', true);
            return;
        }
        const w = Number(wInput.value);
        const h = Number(hInput.value);

        if (!w || !h || w < 1 || h < 1 || w > 12000 || h > 12000) {
            ui.setStatus('Enter a valid width and height between 1 and 12000 px.', true);
            return;
        }

        try {
            const canvas = scaleTo(current.img, w, h);
            const type = formatSelect.value;
            const quality = type === 'image/png' ? undefined : 0.92;
            const blob = await canvasToBlob(canvas, type, quality);
            const ext = type === 'image/webp' ? 'webp' : (type === 'image/png' ? 'png' : 'jpg');

            ui.renderResult({
                blob,
                filename: `${baseName(current.file.name)}-${w}x${h}.${ext}`,
                originalSize: current.file.size,
                extraLines: [['Dimensions', `${w} × ${h} px`]],
            });
            ui.reportSuccess();
        } catch (err) {
            ui.reportError(err.message);
        }
    });
}
