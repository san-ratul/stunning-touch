/** Unicode-safe Base64 encode/decode using TextEncoder/TextDecoder. */

const encoder = new TextEncoder();
const decoder = new TextDecoder('utf-8', { fatal: false });

export function init(el) {
    const input = el.querySelector('[data-input]');
    const output = el.querySelector('[data-output]');
    const status = el.querySelector('[data-status]');
    const urlSafe = el.querySelector('[data-urlsafe]');

    function setStatus(message, ok) {
        status.textContent = message;
        status.className = ok ? 'text-sm font-medium text-emerald-600' : 'text-sm font-medium text-red-600';
    }

    function normalize(b64) {
        b64 = b64.trim().replace(/\s+/g, '').replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4 !== 0) b64 += '=';
        return b64;
    }

    el.querySelector('[data-encode]').addEventListener('click', () => {
        const text = input.value;
        if (!text) {
            setStatus('Enter text to encode.', false);
            return;
        }
        try {
            let b64 = btoa(String.fromCharCode(...encoder.encode(text)));
            if (urlSafe.checked) b64 = b64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
            output.value = b64;
            setStatus('✓ Encoded successfully.', true);
        } catch {
            setStatus('Encoding failed.', false);
        }
    });

    el.querySelector('[data-decode]').addEventListener('click', () => {
        const raw = input.value.trim();
        if (!raw) {
            setStatus('Enter Base64 to decode.', false);
            return;
        }
        try {
            const bytes = Uint8Array.from(atob(normalize(raw)), (ch) => ch.charCodeAt(0));
            output.value = decoder.decode(bytes);
            setStatus('✓ Decoded successfully.', true);
        } catch {
            setStatus('✗ Not valid Base64.', false);
        }
    });

    el.querySelector('[data-copy]').addEventListener('click', async (e) => {
        if (!output.value) return;
        await navigator.clipboard.writeText(output.value);
        flash(e.currentTarget, 'Copied!');
    });

    el.querySelector('[data-clear]').addEventListener('click', () => {
        input.value = '';
        output.value = '';
        status.textContent = '';
        input.focus();
    });

    function flash(btn, message) {
        const original = btn.textContent;
        btn.textContent = message;
        setTimeout(() => { btn.textContent = original; }, 1200);
    }
}
