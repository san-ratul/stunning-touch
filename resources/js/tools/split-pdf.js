import { trackToolUsage } from '../analytics';
import { downloadBlob, formatBytes } from './helpers';

export function init(el) {
    const dropzone = el.querySelector('[data-dropzone]');
    const fileInput = el.querySelector('[data-file-input]');
    const browseBtn = el.querySelector('[data-browse]');
    const infoBox = el.querySelector('[data-file-info]');
    const rangeMode = el.querySelector('[data-mode-range]');
    const extractMode = el.querySelector('[data-mode-extract]');
    const fromInput = el.querySelector('[data-from]');
    const toInput = el.querySelector('[data-to]');
    const extractInput = el.querySelector('[data-pages]');
    const splitBtn = el.querySelector('[data-split]');
    const statusEl = el.querySelector('[data-status]');
    const fileInfoEl = el.querySelector('[data-file-text]');

    let file = null;
    let pageCount = 0;

    function setStatus(message, isError = false) {
        statusEl.textContent = message || '';
        statusEl.classList.toggle('text-red-600', isError);
    }

    async function accept(f) {
        if (!f) return;
        if (f.type !== 'application/pdf' && !f.name.toLowerCase().endsWith('.pdf')) {
            setStatus('Please choose a PDF file.', true);
            return;
        }
        if (f.size > 40 * 1024 * 1024) {
            setStatus(`File exceeds ${formatBytes(40 * 1024 * 1024)}.`, true);
            return;
        }
        try {
            const { PDFDocument } = await import('pdf-lib');
            const doc = await PDFDocument.load(await f.arrayBuffer(), { ignoreEncryption: true });
            pageCount = doc.getPageCount();
            file = f;
            fileInfoEl.textContent = `${f.name} — ${pageCount} pages (${formatBytes(f.size)})`;
            infoBox.hidden = false;
            toInput.max = pageCount;
            toInput.placeholder = String(pageCount);
            setStatus('');
            trackToolUsage(el.dataset.tool, true, 'run');
        } catch (err) {
            setStatus(`Could not read this PDF: ${err.message}`, true);
            trackToolUsage(el.dataset.tool, false);
        }
    }

    dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('is-dragover'); });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('is-dragover'));
    dropzone.addEventListener('drop', (e) => { e.preventDefault(); dropzone.classList.remove('is-dragover'); accept(e.dataTransfer.files[0]); });
    browseBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => accept(fileInput.files[0]));

    splitBtn.addEventListener('click', async () => {
        if (!file) {
            setStatus('Choose a PDF first.', true);
            return;
        }

        let indices;
        const label = rangeMode.checked ? 'range' : 'extract';

        if (label === 'range') {
            const from = Math.max(1, parseInt(fromInput.value, 10) || 1);
            const to = Math.min(pageCount, parseInt(toInput.value, 10) || pageCount);
            if (from > to) {
                setStatus('"From" page must be less than or equal to "To" page.', true);
                return;
            }
            indices = [];
            for (let i = from - 1; i < to; i++) indices.push(i);
        } else {
            const parts = extractInput.value.split(',').map((p) => p.trim()).filter(Boolean);
            indices = [];
            for (const part of parts) {
                const m = part.match(/^(\d+)(?:\s*-\s*(\d+))?$/);
                if (!m) {
                    setStatus(`"${part}" is not a valid page or range. Use e.g. 1,3,5-8.`, true);
                    return;
                }
                const start = parseInt(m[1], 10);
                const end = m[2] ? parseInt(m[2], 10) : start;
                if (start < 1 || end < start || end > pageCount) {
                    setStatus(`Pages must be between 1 and ${pageCount}.`, true);
                    return;
                }
                for (let i = start - 1; i < end; i++) indices.push(i);
            }
            indices = [...new Set(indices)].sort((a, b) => a - b);
        }

        if (!indices.length) {
            setStatus('Select at least one page.', true);
            return;
        }

        splitBtn.disabled = true;
        splitBtn.textContent = 'Extracting…';

        try {
            const { PDFDocument } = await import('pdf-lib');
            const source = await PDFDocument.load(await file.arrayBuffer(), { ignoreEncryption: true });
            const out = await PDFDocument.create();
            const pages = await out.copyPages(source, indices);
            pages.forEach((page) => out.addPage(page));
            out.setProducer('Stunning Touch');

            const bytes = await out.save();
            downloadBlob(new Blob([bytes], { type: 'application/pdf' }), `split-${indices.length}-pages.pdf`);
            setStatus(`Extracted ${indices.length} page(s) successfully.`);
            trackToolUsage(el.dataset.tool, true, 'download');
        } catch (err) {
            setStatus(`Split failed: ${err.message}`, true);
            trackToolUsage(el.dataset.tool, false);
        } finally {
            splitBtn.disabled = false;
            splitBtn.textContent = 'Extract Pages';
        }
    });
}
