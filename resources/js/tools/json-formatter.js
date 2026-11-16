export function init(el) {
    const input = el.querySelector('[data-input]');
    const output = el.querySelector('[data-output]');
    const status = el.querySelector('[data-status]');
    const stats = el.querySelector('[data-stats]');

    function showStatus(message, isValid) {
        status.textContent = message;
        status.className = isValid
            ? 'text-sm font-medium text-emerald-600'
            : 'text-sm font-medium text-red-600';
    }

    function format() {
        const text = input.value.trim();
        if (!text) {
            output.value = '';
            status.textContent = '';
            stats.hidden = true;
            return;
        }
        try {
            const parsed = JSON.parse(text);
            showStatus('✓ Valid JSON', true);
            output.value = JSON.stringify(parsed, null, 2);
            renderStats(parsed);
            stats.hidden = false;
        } catch (err) {
            showStatus(`✗ Invalid JSON — ${err.message}`, false);
            stats.hidden = true;
        }
    }

    function renderStats(parsed) {
        const count = (node) => {
            let n = 0;
            const walk = (v) => {
                if (v && typeof v === 'object') {
                    Object.values(v).forEach(walk);
                }
                n++;
            };
            walk(parsed);
            return n;
        };
        el.querySelector('[data-stat-keys]').textContent = countKeys(parsed).toLocaleString();
        el.querySelector('[data-stat-depth]').textContent = maxDepth(parsed).toLocaleString();
    }

    function countKeys(value) {
        if (Array.isArray(value)) return value.reduce((sum, v) => sum + countKeys(v), 0);
        if (value && typeof value === 'object') {
            return Object.keys(value).length + Object.values(value).reduce((sum, v) => sum + countKeys(v), 0);
        }
        return 0;
    }

    function maxDepth(value, depth = 1) {
        if (Array.isArray(value)) return Math.max(depth, ...value.map((v) => maxDepth(v, depth + 1)), depth);
        if (value && typeof value === 'object') {
            return Math.max(depth, ...Object.values(value).map((v) => maxDepth(v, depth + 1)));
        }
        return depth;
    }

    el.querySelector('[data-format]').addEventListener('click', () => {
        format();
    });

    el.querySelector('[data-minify]').addEventListener('click', () => {
        const text = input.value.trim();
        if (!text) return;
        try {
            const parsed = JSON.parse(text);
            output.value = JSON.stringify(parsed);
            showStatus('✓ Valid JSON (minified)', true);
            renderStats(parsed);
            stats.hidden = false;
        } catch (err) {
            showStatus(`✗ Invalid JSON — ${err.message}`, false);
            stats.hidden = true;
        }
    });

    el.querySelector('[data-validate]').addEventListener('click', format);

    el.querySelector('[data-copy]').addEventListener('click', async (e) => {
        if (!output.value) return;
        await navigator.clipboard.writeText(output.value);
        flash(e.currentTarget, 'Copied!');
    });

    el.querySelector('[data-clear]').addEventListener('click', () => {
        input.value = '';
        output.value = '';
        status.textContent = '';
        stats.hidden = true;
        input.focus();
    });

    function flash(btn, message) {
        const original = btn.textContent;
        btn.textContent = message;
        setTimeout(() => { btn.textContent = original; }, 1200);
    }
}
