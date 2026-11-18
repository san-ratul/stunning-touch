import { trackToolUsage } from '../analytics';

export function init(el) {
    const countInput = el.querySelector('[data-count]');
    const generateBtn = el.querySelector('[data-generate]');
    const copyAllBtn = el.querySelector('[data-copy-all]');
    const uppercase = el.querySelector('[data-uppercase]');
    const noDashes = el.querySelector('[data-nodashes]');
    const list = el.querySelector('[data-list]');
    const status = el.querySelector('[data-status]');

    function generateOne() {
        if (crypto.randomUUID) return crypto.randomUUID();
        // RFC 4122 v4 fallback for older browsers
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            const v = c === 'x' ? r : ((r & 0x3) | 0x8);
            return v.toString(16);
        });
    }

    function format(uuid) {
        let value = uuid;
        if (uppercase.checked) value = value.toUpperCase();
        if (noDashes.checked) value = value.replace(/-/g, '');
        return value;
    }

    generateBtn.addEventListener('click', () => {
        const count = Math.min(100, Math.max(1, parseInt(countInput.value, 10) || 1));

        list.innerHTML = '';
        for (let i = 0; i < count; i++) {
            const value = format(generateOne());
            const row = document.createElement('li');
            row.className = 'group flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-2.5 font-mono text-sm text-slate-800';
            const span = document.createElement('span');
            span.className = 'min-w-0 flex-1 truncate';
            span.textContent = value;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-ghost px-2 py-1 text-xs';
            btn.textContent = 'Copy';
            btn.addEventListener('click', async () => {
                await navigator.clipboard.writeText(value);
                btn.textContent = 'Copied!';
                setTimeout(() => { btn.textContent = 'Copy'; }, 1200);
            });
            row.append(span, btn);
            list.appendChild(row);
        }

        copyAllBtn.hidden = count <= 1;
        status.textContent = `${count} UUID v${4} value${count > 1 ? 's' : ''} generated.`;
        trackToolUsage(el.dataset.tool, true);
    });

    copyAllBtn.addEventListener('click', async () => {
        const values = [...list.querySelectorAll('span')].map((s) => s.textContent);
        await navigator.clipboard.writeText(values.join('\n'));
        flash(copyAllBtn, 'All copied!');
    });

    function flash(btn, message) {
        const original = btn.textContent;
        btn.textContent = message;
        setTimeout(() => { btn.textContent = original; }, 1200);
    }
}
