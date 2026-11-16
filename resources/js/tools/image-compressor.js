import { createImageToolUI, canvasToBlob } from './image-tool';
import { baseName } from './helpers';

export function init(el) {
    const ui = createImageToolUI(el, {
        onImage(img, file) {
            const canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth;
            canvas.height = img.naturalHeight;
            canvas.getContext('2d').drawImage(img, 0, 0);

            const qualityRange = el.querySelector('[data-quality]');
            const formatSelect = el.querySelector('[data-format]');
            const controls = el.querySelector('[data-controls]');
            controls.hidden = false;

            const process = async () => {
                const type = formatSelect.value;
                const quality = Number(qualityRange.value);

                try {
                    const blob = await canvasToBlob(canvas, type, quality);
                    const ext = type === 'image/webp' ? 'webp' : (type === 'image/png' ? 'png' : 'jpg');
                    ui.renderResult({
                        blob,
                        filename: `${baseName(file.name)}-compressed.${ext}`,
                        originalSize: file.size,
                        extraLines: [['Quality', `${Math.round(quality * 100)}%`]],
                    });
                    ui.reportSuccess();
                } catch (err) {
                    ui.reportError(err.message);
                }
            };

            qualityRange.oninput = () => {
                el.querySelector('[data-quality-label]').textContent = `${qualityRange.value}%`;
                process();
            };
            formatSelect.onchange = () => {
                const isPng = formatSelect.value === 'image/png';
                qualityRange.disabled = isPng;
                process();
            };

            process();
        },
    });
}
