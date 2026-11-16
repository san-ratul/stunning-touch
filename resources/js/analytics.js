/**
 * Analytics helpers.
 * - trackToolUsage: fire-and-forget beacon after a tool actually processes.
 * - Locale hint: once per session, share the browser locale's region so
 *   country analytics work without edge geo headers. Approximate only.
 */

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content;
}

export function trackToolUsage(componentKey, success = true, event = 'run') {
    try {
        const csrf = csrfToken();
        if (!csrf) return;

        const body = new URLSearchParams({ tool: componentKey, success: String(success), event });
        body.append('_token', csrf);

        fetch('/api/tool-usage', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            keepalive: true,
        }).catch(() => {});
    } catch {
        /* analytics must never break tools */
    }
}

function sendLocaleHint() {
    try {
        if (sessionStorage.getItem('st-loc-sent')) return;
        sessionStorage.setItem('st-loc-sent', '1');

        const csrf = csrfToken();
        if (!csrf) return;

        const region = (navigator.language || '').split('-')[1] || '';
        const body = new URLSearchParams();
        if (region.length === 2) body.append('country', region.toUpperCase());
        body.append('_token', csrf);

        fetch('/api/visitor-locale', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            keepalive: true,
        }).catch(() => {});
    } catch {
        /* never break the page */
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sendLocaleHint);
} else {
    sendLocaleHint();
}
