import QRCode from 'qrcode';
import { trackToolUsage } from '../analytics';

export function init(el) {
    const input = el.querySelector('[data-text]');
    const sizeInput = el.querySelector('[data-size]');
    const marginInput = el.querySelector('[data-margin]');
    const eccSelect = el.querySelector('[data-ecc]');
    const fgColor = el.querySelector('[data-fg]');
    const bgColor = el.querySelector('[data-bg]');
    const preview = el.querySelector('[data-preview]');
    const downloadPng = el.querySelector('[data-download-png]');
    const downloadSvg = el.querySelector('[data-download-svg]');
    const status = el.querySelector('[data-status]');

    let currentCanvas = document.createElement('canvas');
    let currentSvg = '';

    async function generate() {
        const text = input.value.trim();
        if (!text) {
            status.textContent = 'Enter text or a URL to generate a QR code.';
            status.className = 'text-sm text-slate-500';
            preview.hidden = true;
            downloadPng.disabled = true;
            downloadSvg.disabled = true;
            return;
        }

        try {
            const options = {
                width: Number(sizeInput.value),
                margin: Number(marginInput.value),
                errorCorrectionLevel: eccSelect.value,
                color: { dark: fgColor.value, light: bgColor.value },
            };

            await QRCode.toCanvas(currentCanvas, text, options);
            currentSvg = await QRCode.toString(text, { ...options, type: 'svg' });

            currentCanvas.className = 'mx-auto max-w-full h-auto rounded-xl border border-slate-200 bg-white';
            preview.innerHTML = '';
            preview.appendChild(currentCanvas);
            preview.hidden = false;
            downloadPng.disabled = false;
            downloadSvg.disabled = false;
            status.textContent = '';
            status.className = 'text-sm text-slate-500';
            trackToolUsage(el.dataset.tool, true);
        } catch (err) {
            status.textContent = `Could not generate QR code: ${err.message}`;
            status.className = 'text-sm font-medium text-red-600';
            trackToolUsage(el.dataset.tool, false);
        }
    }

    input.addEventListener('input', debounce(generate, 250));
    [sizeInput, marginInput, eccSelect, fgColor, bgColor].forEach((n) => n.addEventListener('change', generate));
    [sizeInput, marginInput].forEach((n) => n.addEventListener('input', debounce(generate, 250)));

    downloadPng.addEventListener('click', () => {
        currentCanvas.toBlob((blob) => {
            if (!blob) return;
            const url = URL.createObjectURL(blob);
            triggerDownload(url, 'qr-code.png');
            setTimeout(() => URL.revokeObjectURL(url), 3000);
            trackToolUsage(el.dataset.tool, true, 'download');
        }, 'image/png');
    });

    downloadSvg.addEventListener('click', () => {
        if (!currentSvg) return;
        const url = URL.createObjectURL(new Blob([currentSvg], { type: 'image/svg+xml' }));
        triggerDownload(url, 'qr-code.svg');
        setTimeout(() => URL.revokeObjectURL(url), 3000);
        trackToolUsage(el.dataset.tool, true, 'download');
    });

    function triggerDownload(url, name) {
        const a = document.createElement('a');
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    function debounce(fn, wait) {
        let t;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...args), wait);
        };
    }
}
