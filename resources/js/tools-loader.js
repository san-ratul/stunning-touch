/**
 * Tool module loader.
 *
 * Each functional tool page declares `data-tool="<component_key>"` on its
 * mount element. The matching module under ./tools/<key>.js must export
 * `init(el)`. The component_key comes from the hardcoded ToolRegistry —
 * the database can never point at arbitrary code.
 */
export function mountTool() {
    const el = document.querySelector('[data-tool]');
    if (!el) return;

    const key = el.dataset.tool;
    if (!key || !/^[a-z0-9-]+$/.test(key)) return;

    import(`./tools/${key}.js`)
        .then((mod) => {
            if (typeof mod.init === 'function') mod.init(el);
        })
        .catch(() => {
            el.querySelectorAll('[data-tool-loading]')?.forEach((n) => n.remove());
        });
}
