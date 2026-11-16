import { createImageToolUI, canvasToBlob } from './image-tool';
import { baseName } from './helpers';

export function init(el) {
    const ui = createImageToolUI(el, {
        onImage(img, file) {
            const canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth;
            canvas.height = img.naturalHeight;

            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0);

            canvasToBlob(canvas, 'image/png')
                .then((blob) => {
                    ui.renderResult({
                        blob,
                        filename: `${baseName(file.name)}.png`,
                        originalSize: file.size,
                        extraLines: [['Dimensions', `${canvas.width} × ${canvas.height} px`]],
                    });
                    ui.reportSuccess();
                })
                .catch((err) => ui.reportError(err.message));
        },
    });
}
