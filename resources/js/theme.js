/**
 * Color theme: light / dark / system, persisted in localStorage.
 * A pre-paint inline snippet in each layout applies the class before
 * first paint; this module keeps it in sync, powers the toggles and
 * shows the correct icon via inline styles (immune to CSS build order).
 *
 * Precedence: manual toggle > ?theme= query param > saved preference > system.
 */
const KEY = 'st-theme';
const ORDER = ['light', 'dark', 'system'];
const ICON = { light: 'ti-sun', dark: 'ti-moon', system: 'ti-system' };
let manual = false;

function currentTheme() {
    if (manual) return localStorage.getItem(KEY) || 'system';
    const q = new URLSearchParams(location.search).get('theme');
    if (ORDER.includes(q)) return q;
    return localStorage.getItem(KEY) || 'system';
}

export function applyTheme() {
    const t = currentTheme();
    const dark = t === 'dark' ||
        (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.dataset.theme = t;

    document.querySelectorAll('[data-theme-toggle] .ti').forEach((el) => {
        el.style.display = el.classList.contains(ICON[t]) ? 'block' : 'none';
    });
}

export function initTheme() {
    applyTheme();

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (currentTheme() === 'system') applyTheme();
    });

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-theme-toggle]');
        if (!btn) return;

        const next = ORDER[(ORDER.indexOf(currentTheme()) + 1) % ORDER.length];
        manual = true;
        localStorage.setItem(KEY, next);
        applyTheme();
    });
}
