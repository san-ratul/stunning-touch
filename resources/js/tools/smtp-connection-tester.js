/**
 * SMTP Connection & Email Tester UI.
 *
 * Two strictly separate operations:
 *  - "Test Connection" → POST /api/smtp/test-connection (never sends email)
 *  - "Send Test Email" → POST /api/smtp/send-test-email (sends one email)
 *
 * Security behaviors:
 * - Passwords are never saved anywhere (no localStorage, no sessionStorage).
 * - The password field is cleared after every operation.
 * - Requests are POST with CSRF token; errors are shown as sanitized text.
 */

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content;
}

export function init(el) {
    const form = el.querySelector('[data-form]');
    const tabConnection = el.querySelector('[data-tab="connection"]');
    const tabSend = el.querySelector('[data-tab="send"]');
    const sendOnlyFields = [...el.querySelectorAll('[data-send-only]')];
    const authFields = el.querySelector('[data-credentials]');
    const verifyAuthBox = el.querySelector('[data-verify-auth]');
    const encryptionSelect = el.querySelector('[data-encryption]');
    const warning = el.querySelector('[data-encryption-warning]');
    const submitConnection = el.querySelector('[data-submit-connection]');
    const submitSend = el.querySelector('[data-submit-send]');
    const reset = el.querySelector('[data-reset]');
    const statusBox = el.querySelector('[data-status]');
    const resultPanel = el.querySelector('[data-result]');
    const resultTitle = el.querySelector('[data-result-title]');
    const resultMessage = el.querySelector('[data-result-message]');
    const stepsList = el.querySelector('[data-steps]');
    const detailsGrid = el.querySelector('[data-details]');
    const privacyNote = el.querySelector('[data-privacy]');

    let busy = false;

    encryptionSelect.addEventListener('change', updateEncryptionWarning);

    verifyAuthBox.addEventListener('change', updateAuthVisibility);

    for (const tab of [tabConnection, tabSend]) {
        tab.addEventListener('click', () => {
            const mode = tab.dataset.tab;

            tabConnection.classList.toggle('active-tab', mode === 'connection');
            tabSend.classList.toggle('active-tab', mode === 'send');
            tabConnection.setAttribute('aria-selected', String(mode === 'connection'));
            tabSend.setAttribute('aria-selected', String(mode === 'send'));

            for (const field of sendOnlyFields) {
                field.hidden = mode !== 'send';
            }

            updateAuthVisibility();
            clearStatus();
        });
    }

    submitConnection.addEventListener('click', () => submit('connection'));
    submitSend.addEventListener('click', () => submit('send'));

    reset.addEventListener('click', () => {
        form.reset();
        updateEncryptionWarning();
        updateAuthVisibility();
        clearStatus();
    });

    function updateAuthVisibility() {
        const sendMode = tabSend.classList.contains('active-tab');
        authFields.hidden = !(sendMode || verifyAuthBox.checked);
    }

    function updateEncryptionWarning() {
        const value = encryptionSelect.value;
        warning.hidden = value !== 'none';
    }

    function clearStatus() {
        statusBox.hidden = true;
        resultPanel.hidden = true;
    }

    function collect() {
        return {
            host: el.querySelector('[data-host]').value.trim(),
            port: el.querySelector('[data-port]').value.trim(),
            encryption: encryptionSelect.value,
            username: el.querySelector('[data-username]')?.value ?? '',
            password: el.querySelector('[data-password]')?.value ?? '',
            from_email: el.querySelector('[data-from]')?.value.trim() ?? '',
            to_email: el.querySelector('[data-to]')?.value.trim() ?? '',
            subject: el.querySelector('[data-subject]')?.value.trim() ?? '',
            message: el.querySelector('[data-message]')?.value ?? '',
        };
    }

    function activeMode() {
        return tabSend.classList.contains('active-tab') ? 'send' : 'connection';
    }

    async function submit(kind) {
        if (busy) return;

        const mode = activeMode();
        const endpoint = mode === 'send' ? '/api/smtp/send-test-email' : '/api/smtp/test-connection';
        const payload = collect();

        if (mode === 'send') {
            payload.verify_auth = true; // sending always authenticates when credentials are set
        } else {
            payload.verify_auth = verifyAuthBox.checked;
        }

        busy = true;
        setButtons(true);
        showStatus(mode === 'send'
            ? 'Connecting, authenticating and sending the test email…'
            : 'Connecting to the SMTP server…');

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            });

            await handleResponse(response, kind);
        } catch {
            showStatus('The request could not be sent. Check your internet connection and try again.', 'error');
        } finally {
            busy = false;
            setButtons(false);
            // Credentials never outlive the operation.
            const passwordField = el.querySelector('[data-password]');
            if (passwordField) passwordField.value = '';
        }
    }

    async function handleResponse(response, kind) {
        if (response.status === 422) {
            const data = await response.json().catch(() => null);
            const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
            showStatus(first ?? 'Please check the form — some fields are invalid.', 'error');
            return;
        }

        if (response.status === 429) {
            showStatus('Too many requests. The tool is rate-limited to protect the service — please wait a minute and try again.', 'error');
            return;
        }

        if (!response.ok) {
            showStatus('The tester service is temporarily unavailable. Please try again later.', 'error');
            return;
        }

        const data = await response.json().catch(() => null);
        const result = data?.result;

        if (!result) {
            showStatus('Unexpected response from the server.', 'error');
            return;
        }

        renderResult(result, kind);
    }

    function renderResult(result, kind) {
        statusBox.hidden = true;
        resultPanel.hidden = false;

        const state = result.status === 'ok' ? 'success' : result.status === 'warning' ? 'warning' : 'error';
        resultPanel.className = 'mt-6 rounded-2xl border p-5 ' + panelClass(state);

        resultTitle.textContent = kind === 'send' ? 'Send test email' : 'Connection test';
        resultTitle.className = 'text-sm font-bold uppercase tracking-wide ' + titleClass(state);

        resultMessage.textContent = result.message ?? '';

        stepsList.textContent = '';

        for (const step of result.steps ?? []) {
            const li = document.createElement('li');
            li.className = 'flex items-start gap-2.5 py-1.5';

            const dot = document.createElement('span');
            dot.className = 'mt-1 h-2 w-2 shrink-0 rounded-full ' + dotClass(step.status);
            li.appendChild(dot);

            const label = document.createElement('span');
            label.className = 'text-sm font-semibold text-slate-700';
            label.textContent = step.label;
            li.appendChild(label);

            const detail = document.createElement('span');
            detail.className = 'text-sm text-slate-500';
            detail.textContent = '— ' + step.detail;
            li.appendChild(detail);

            stepsList.appendChild(li);
        }

        detailsGrid.textContent = '';
        detailsGrid.hidden = true;

        const details = buildDetails(result, kind);

        if (details.length > 0) {
            detailsGrid.hidden = false;

            for (const [label, value] of details) {
                const item = document.createElement('div');
                item.className = 'rounded-xl border border-slate-200 bg-slate-50/70 p-3';

                const dt = document.createElement('p');
                dt.className = 'text-xs font-semibold uppercase tracking-wide text-slate-500';
                dt.textContent = label;
                item.appendChild(dt);

                const dd = document.createElement('p');
                dd.className = 'mt-1 break-all font-mono text-xs text-slate-700';
                dd.textContent = value;
                item.appendChild(dd);

                detailsGrid.appendChild(item);
            }
        }

        privacyNote.hidden = false;
    }

    function buildDetails(result, kind) {
        const details = [
            ['Host', result.host],
            ['Port', String(result.port)],
            ['Encryption', { ssl: 'SSL/TLS (implicit)', starttls: 'STARTTLS', none: 'None (plain text!)' }[result.encryption] ?? result.encryption],
        ];

        if (result.latency_ms !== null && result.latency_ms !== undefined) {
            details.push(['Connection latency', `${result.latency_ms} ms`]);
        }

        if (result.greeting) {
            details.push(['Server greeting', result.greeting.split('\n')[0]]);
        }

        if (result.tls) {
            details.push(['TLS', { implicit: 'Negotiated on connect (SSL/TLS)', negotiated: 'Negotiated via STARTTLS', 'not-required': 'Not used (plain connection)' }[result.tls] ?? result.tls]);
        }

        if (result.authenticated !== null && result.authenticated !== undefined) {
            details.push(['Authentication', result.authenticated ? 'Credentials accepted' : 'Failed / not verified']);
        }

        if (result.smtp_code) {
            details.push(['Last SMTP code', String(result.smtp_code)]);
        }

        if (kind === 'send' && result.status === 'ok' && result.subject) {
            details.push(['Subject', result.subject]);
        }

        if ((result.capabilities ?? []).length > 0) {
            details.push(['Server capabilities', result.capabilities.join(', ')]);
        }

        return details;
    }

    function showStatus(message, state = 'info') {
        statusBox.hidden = false;
        statusBox.className = 'mt-6 rounded-xl border px-4 py-3 text-sm font-medium ' + statusClass(state);
        statusBox.textContent = message;
        resultPanel.hidden = true;
    }

    function setButtons(disabled) {
        submitConnection.disabled = disabled;
        submitSend.disabled = disabled;
        reset.disabled = disabled;
    }

    function panelClass(state) {
        return { success: 'border-emerald-200 bg-emerald-50/60', warning: 'border-amber-200 bg-amber-50/60', error: 'border-rose-200 bg-rose-50/60', info: 'border-slate-200' }[state];
    }

    function titleClass(state) {
        return { success: 'text-emerald-700', warning: 'text-amber-700', error: 'text-rose-700', info: 'text-slate-700' }[state];
    }

    function dotClass(state) {
        return { ok: 'bg-emerald-500', warn: 'bg-amber-500', error: 'bg-rose-500', info: 'bg-slate-400' }[state];
    }

    function statusClass(state) {
        return {
            info: 'border-slate-200 bg-slate-50 text-slate-600',
            error: 'border-rose-200 bg-rose-50 text-rose-700',
            success: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        }[state] ?? 'border-slate-200 bg-slate-50 text-slate-600';
    }

    updateEncryptionWarning();
}
