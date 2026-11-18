import { trackToolUsage } from '../analytics';

const transforms = {
    upper: (t) => t.toUpperCase(),
    lower: (t) => t.toLowerCase(),
    title: (t) => t.toLowerCase().replace(/\b([a-z])/g, (m) => m.toUpperCase()),
    sentence: (t) => t.toLowerCase().replace(/(^\s*[a-z])|((?:[.!?]\s+)[a-z])/g, (m) => m.toUpperCase()),
    capitalized: (t) => t.replace(/\b[a-z]/gi, (m) => m.toUpperCase()),
    inverse: (t) => t.replace(/[a-zA-Z]/g, (m) => (m === m.toUpperCase() ? m.toLowerCase() : m.toUpperCase())),
};

export function init(el) {
    const input = el.querySelector('[data-input]');
    const output = el.querySelector('[data-output]');
    const buttons = el.querySelectorAll('[data-transform]');
    const placeholder = output.getAttribute('data-placeholder') ?? '';

    buttons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const mode = btn.dataset.transform;
            const fn = transforms[mode];
            if (!fn) return;
            output.value = fn(input.value);
            buttons.forEach((b) => b.classList.remove('btn-primary'));
            btn.classList.add('btn-primary');

            // Count real transforms only (a click on empty input changed nothing).
            if (input.value.trim() !== '') {
                trackToolUsage(el.dataset.tool, true);
            }
        });
    });

    el.querySelector('[data-copy]').addEventListener('click', async (e) => {
        if (!output.value) return;
        await navigator.clipboard.writeText(output.value);
        flash(e.currentTarget, 'Copied!');
    });

    el.querySelector('[data-clear]').addEventListener('click', () => {
        input.value = '';
        output.value = '';
        buttons.forEach((b) => b.classList.remove('btn-primary'));
        input.focus();
    });

    function flash(btn, message) {
        const original = btn.textContent;
        btn.textContent = message;
        setTimeout(() => { btn.textContent = original; }, 1200);
    }
}
