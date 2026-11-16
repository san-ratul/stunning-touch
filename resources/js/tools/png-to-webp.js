import { createImageToolUI, canvasToBlob } from './image-tool';
import { baseName } from './helpers';

export function init(el) {
    const qualityRange = el.querySelector('[data-quality]');
    const controls = el.querySelector('[data-controls]');
    let pending = null;

    const ui = createImageToolUI(el, {
        onImage(img, file) {
            pending = { img, file };
            controls.hidden = false;
            process();
        },
    });

    qualityRange.oninput = () => {
        el.querySelector('[data-quality-label]').textContent = `${qualityRange.value}%`;
        if (pending) process();
    };

    async function process() {
        if (!pending) return;
        try {
            const canvas = document.createElement('canvas');
            canvas.width = pending.img.naturalWidth;
            canvas.height = pending.img.naturalHeight;
            canvas.getContext('2d').drawImage(pending.img, 0, 0);

            const blob = await canvasToBlob(canvas, 'image/webp', Number(qualityRange.value) / 100);

            ui.renderResult({
                blob,
                filename: `${baseName(pending.file.name)}.webp`,
                originalSize: pending.file.size,
                extraLines: [['Quality', `${qualityRange.value}%`]],
            });
            ui.reportSuccess();
        } catch (err) {
            ui.reportError(err.message || 'This browser cannot export WebP.');
        }
    }
}
