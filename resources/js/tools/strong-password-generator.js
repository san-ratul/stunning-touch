/**
 * Strong Password Generator UI.
 *
 * Fully client-side: passwords are generated in the browser with
 * crypto.getRandomValues(), never sent to any server, never stored
 * (no localStorage/sessionStorage), never logged, never analyzed.
 */
import {
    buildPool,
    DEFAULT_LENGTH,
    estimateEntropy,
    generatePasswords,
    MAX_LENGTH,
    MIN_LENGTH,
    strengthLabel,
} from './password-core.js';
import { trackToolUsage } from '../analytics';

function randomBytes(count) {
    const buffer = new Uint32Array(count);
    crypto.getRandomValues(buffer);

    return buffer;
}

/** One unbiased random uint32 from crypto.getRandomValues(). */
function randomUint32() {
    return randomBytes(1)[0];
}

export function init(el) {
    const lengthRange = el.querySelector('[data-length-range]');
    const lengthNumber = el.querySelector('[data-length-number]');
    const groupBoxes = [...el.querySelectorAll('[data-group]')];
    const excludeAmbiguous = el.querySelector('[data-exclude-ambiguous]');
    const countSelect = el.querySelector('[data-count]');
    const generate = el.querySelector('[data-generate]');
    const clear = el.querySelector('[data-clear]');
    const copyAll = el.querySelector('[data-copy-all]');
    const output = el.querySelector('[data-output]');
    const emptyState = el.querySelector('[data-empty]');
    const errorState = el.querySelector('[data-error]');
    const strengthBar = el.querySelector('[data-strength-bar]');
    const strengthLabelNode = el.querySelector('[data-strength-label]');
    const entropyNode = el.querySelector('[data-entropy]');
    const poolNode = el.querySelector('[data-pool]');
    const lengthInfoNodes = [...el.querySelectorAll('[data-length-info], [data-length-info-secondary]')];

    generate.addEventListener('click', run);
    clear.addEventListener('click', resetAll);
    copyAll.addEventListener('click', copyEverything);

    lengthRange.addEventListener('input', () => {
        lengthNumber.value = lengthRange.value;
        updateStrength();
    });

    lengthNumber.addEventListener('change', () => {
        let value = parseInt(lengthNumber.value, 10);

        if (Number.isNaN(value)) {
            value = DEFAULT_LENGTH;
        }

        value = Math.min(Math.max(value, MIN_LENGTH), MAX_LENGTH);
        lengthNumber.value = String(value);
        lengthRange.value = String(value);
        updateStrength();
    });

    for (const box of groupBoxes) {
        box.addEventListener('change', () => {
            if (groupBoxes.every((b) => !b.checked)) {
                errorState.hidden = false;
            } else {
                errorState.hidden = true;
                updateStrength();
            }
        });
    }

    excludeAmbiguous.addEventListener('change', updateStrength);

    function currentOptions() {
        return {
            upper: groupBoxes.find((b) => b.dataset.group === 'upper')?.checked ?? false,
            lower: groupBoxes.find((b) => b.dataset.group === 'lower')?.checked ?? false,
            number: groupBoxes.find((b) => b.dataset.group === 'number')?.checked ?? false,
            symbol: groupBoxes.find((b) => b.dataset.group === 'symbol')?.checked ?? false,
            excludeAmbiguous: excludeAmbiguous.checked,
        };
    }

    function currentLength() {
        return parseInt(lengthRange.value, 10);
    }

    function resetAll() {
        output.textContent = '';
        output.hidden = true;
        copyAll.hidden = true;
        emptyState.hidden = false;
        errorState.hidden = true;
    }

    function run() {
        const options = currentOptions();
        const { pool, groups } = buildPool(options);

        if (pool === '' || groups.length === 0) {
            errorState.hidden = false;
            resetAll();
            trackToolUsage(el.dataset.tool, false);
            return;
        }

        errorState.hidden = true;

        const passwords = generatePasswords(options, currentLength(), parseInt(countSelect.value, 10), randomUint32);

        if (passwords.length === 0) {
            errorState.hidden = false;
            trackToolUsage(el.dataset.tool, false);
            return;
        }

        emptyState.hidden = true;
        output.textContent = '';
        output.hidden = false;
        copyAll.hidden = passwords.length < 2;

        for (const password of passwords) {
            output.appendChild(buildPasswordRow(password));
        }

        updateStrength();
        trackToolUsage(el.dataset.tool, true);
    }

    function buildPasswordRow(password) {
        const row = document.createElement('div');
        row.className = 'flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50/70 p-3';

        const value = document.createElement('code');
        value.className = 'min-w-0 flex-1 break-all font-mono text-sm font-semibold text-slate-800 select-all';
        value.textContent = password;
        value.dataset.value = password;
        row.appendChild(value);

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'btn-ghost shrink-0 px-2 py-1 text-xs';
        toggle.textContent = 'Reveal';
        toggle.setAttribute('aria-label', 'Toggle password visibility');

        const toggleState = { visible: false };
        toggle.addEventListener('click', () => {
            toggleState.visible = !toggleState.visible;
            value.textContent = toggleState.visible ? password : '•'.repeat(password.length);
            toggle.textContent = toggleState.visible ? 'Hide' : 'Reveal';
        });

        value.textContent = '•'.repeat(password.length);
        row.appendChild(toggle);

        const copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'btn-secondary shrink-0 px-2.5 py-1 text-xs';
        copy.textContent = 'Copy';
        copy.setAttribute('aria-label', 'Copy password to clipboard');

        copy.addEventListener('click', async (e) => {
            await navigator.clipboard.writeText(password);
            flash(e.currentTarget, 'Copied!');
        });

        row.appendChild(copy);

        return row;
    }

    function updateStrength() {
        const options = currentOptions();
        const { pool, poolSize } = buildPool(options);
        const length = currentLength();
        const entropy = estimateEntropy(poolSize, length);
        const strength = strengthLabel(entropy);

        strengthBar.style.width = `${Math.min(100, (strength.level / 4) * 100)}%`;
        strengthBar.className = 'h-2.5 rounded-full transition-all duration-300 ' + strengthColor(strength.level);

        strengthLabelNode.textContent = entropy > 0 ? strength.label : '—';
        entropyNode.textContent = entropy > 0 ? `${entropy} bits` : '0 bits';
        poolNode.textContent = String(poolSize);

        for (const node of lengthInfoNodes) {
            node.textContent = String(length);
        }
    }

    function strengthColor(level) {
        return {
            1: 'bg-rose-500',
            2: 'bg-amber-500',
            3: 'bg-emerald-500',
            4: 'bg-brand-600',
        }[level];
    }

    async function copyEverything() {
        const values = [...output.querySelectorAll('code')].map((node) => node.dataset.value ?? node.textContent);

        await navigator.clipboard.writeText(values.join('\n'));
        flash(copyAll, 'Copied all!');
    }

    function flash(btn, message) {
        const original = btn.textContent;
        btn.textContent = message;
        setTimeout(() => {
            btn.textContent = original;
        }, 1200);
    }

    updateStrength();
    resetAll();
}
