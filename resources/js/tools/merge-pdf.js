import { trackToolUsage } from '../analytics';
import { downloadBlob, formatBytes } from './helpers';

export function init(el) {
    const dropzone = el.querySelector('[data-dropzone]');
    const fileInput = el.querySelector('[data-file-input]');
    const browseBtn = el.querySelector('[data-browse]');
    const list = el.querySelector('[data-list]');
    const mergeBtn = el.querySelector('[data-merge]');
    const statusEl = el.querySelector('[data-status]');
    const resultBox = el.querySelector('[data-results]');

    let files = [];

    const maxBytes = 40 * 1024 * 1024;

    function setStatus(message, isError = false) {
        statusEl.textContent = message || '';
        statusEl.classList.toggle('text-red-600', isError);
    }

    function renderList() {
        list.innerHTML = '';
        files.forEach((item, index) => {
            const row = document.createElement('li');
            row.className = 'flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm';
            row.innerHTML = `
                <span class="text-slate-400" title="Drag to reorder">⠿</span>
                <span class="min-w-0 flex-1 truncate font-medium text-slate-800">${index + 1}. ${item.name}</span>
                <span class="text-slate-500">${formatBytes(item.file.size)}</span>
                <div class="flex gap-1">
                    <button type="button" class="btn-ghost px-2 py-1" data-up ${index === 0 ? 'disabled' : ''} aria-label="Move up">↑</button>
                    <button type="button" class="btn-ghost px-2 py-1" data-down ${index === files.length - 1 ? 'disabled' : ''} aria-label="Move down">↓</button>
                    <button type="button" class="btn-danger px-2 py-1" data-remove aria-label="Remove">✕</button>
                </div>`;
            row.querySelector('[data-up]').onclick = () => {
                [files[index - 1], files[index]] = [files[index], files[index - 1]];
                renderList();
            };
            row.querySelector('[data-down]').onclick = () => {
                [files[index + 1], files[index]] = [files[index], files[index + 1]];
                renderList();
            };
            row.querySelector('[data-remove]').onclick = () => {
                files.splice(index, 1);
                renderList();
            };
            list.appendChild(row);
        });
        mergeBtn.disabled = files.length < 2;
        mergeBtn.textContent = files.length < 2
            ? 'Select at least 2 PDFs'
            : `Merge ${files.length} PDFs`;
    }

    function addFiles(fileList) {
        for (const file of Array.from(fileList)) {
            if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                setStatus(`"${file.name}" is not a PDF.`, true);
                continue;
            }
            if (file.size > maxBytes) {
                setStatus(`"${file.name}" exceeds ${formatBytes(maxBytes)}.`, true);
                continue;
            }
            files.push({ file, name: file.name });
        }
        setStatus('');
        renderList();
    }

    dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('is-dragover'); });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('is-dragover'));
    dropzone.addEventListener('drop', (e) => { e.preventDefault(); dropzone.classList.remove('is-dragover'); addFiles(e.dataTransfer.files); });
    browseBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => addFiles(fileInput.files));

    mergeBtn.addEventListener('click', async () => {
        if (files.length < 2) return;
        mergeBtn.disabled = true;
        mergeBtn.textContent = 'Merging…';

        try {
            const { PDFDocument } = await import('pdf-lib');
            const merged = await PDFDocument.create();

            for (const item of files) {
                const bytes = await item.file.arrayBuffer();
                const doc = await PDFDocument.load(bytes, { ignoreEncryption: true });
                const pages = await merged.copyPages(doc, doc.getPageIndices());
                pages.forEach((page) => merged.addPage(page));
            }

            merged.setProducer('Stunning Touch');
            const bytes = await merged.save();

            downloadBlob(new Blob([bytes], { type: 'application/pdf' }), 'merged.pdf');
            setStatus(`Merged ${files.length} PDFs into ${merged.getPageCount()} pages.`);
            resultBox.hidden = false;
            trackToolUsage(el.dataset.tool, true, 'download');
        } catch (err) {
            setStatus(`Merge failed: ${err.message}`, true);
            trackToolUsage(el.dataset.tool, false);
        } finally {
            mergeBtn.disabled = files.length < 2;
            mergeBtn.textContent = files.length < 2 ? 'Select at least 2 PDFs' : `Merge ${files.length} PDFs`;
        }
    });
}
