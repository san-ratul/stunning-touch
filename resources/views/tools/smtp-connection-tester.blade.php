@extends('layouts.tool')

@section('tool')
<div data-tool="{{ $tool->component_key }}" x-data="{ mode: 'connection' }">

    {{-- Mode tabs --}}
    <div class="flex flex-wrap gap-2" role="tablist" aria-label="SMTP test mode">
        <button type="button" class="active-tab rounded-xl px-4 py-2 text-sm font-semibold transition-colors" data-tab="connection" role="tab" aria-selected="true">
            Test Connection
        </button>
        <button type="button" class="rounded-xl px-4 py-2 text-sm font-semibold transition-colors" data-tab="send" role="tab" aria-selected="false">
            Send Test Email
        </button>
    </div>
    <style>
        [data-tab].active-tab { background: rgb(219 234 254); color: rgb(29 78 216); }
        [data-tab]:not(.active-tab) { background: transparent; color: rgb(71 85 105); }
        [data-tab]:not(.active-tab):hover { background: rgb(241 245 249); }
    </style>

    <form data-form class="mt-5" autocomplete="off" @submit.prevent>
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label class="label" for="smtp-host">SMTP host</label>
                <input type="text" id="smtp-host" class="input" placeholder="smtp.example.com" data-host required>
            </div>
            <div>
                <label class="label" for="smtp-port">Port</label>
                <input type="number" id="smtp-port" class="input" placeholder="587" min="1" max="65535" data-port value="587" required>
            </div>
        </div>

        <div class="mt-4">
            <label class="label" for="smtp-encryption">Encryption</label>
            <select id="smtp-encryption" class="input" data-encryption>
                <option value="starttls" selected>STARTTLS (usually port 587)</option>
                <option value="ssl">SSL/TLS (usually port 465)</option>
                <option value="none">None — plain SMTP (not recommended)</option>
            </select>
            <div class="mt-2 hidden rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" data-encryption-warning role="alert">
                With "None", credentials are transmitted in plain text and can be intercepted. Prefer STARTTLS or SSL/TLS.
            </div>
        </div>

        {{-- Optional authentication (connection mode) --}}
        <div class="mt-4">
            <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                <input type="checkbox" class="h-4 w-4 accent-brand-600" data-verify-auth>
                Also verify authentication in connection mode (uses the credentials below)
            </label>

            <div class="mt-3 grid gap-4 sm:grid-cols-2" data-credentials hidden>
                <div>
                    <label class="label" for="smtp-username">SMTP username</label>
                    <input type="text" id="smtp-username" class="input" placeholder="user@example.com" data-username autocomplete="off">
                </div>
                <div>
                    <label class="label" for="smtp-password">SMTP password</label>
                    <input type="password" id="smtp-password" class="input" placeholder="••••••••" data-password autocomplete="new-password">
                </div>
                <p class="text-xs text-slate-500 sm:col-span-2">Credentials are used only for the operation you trigger and are cleared automatically afterwards — never stored.</p>
            </div>
        </div>

        {{-- Send-mode-only fields --}}
        <div data-send-only hidden>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="smtp-from">Sender email (From)</label>
                    <input type="email" id="smtp-from" class="input" placeholder="you@example.com" data-from required>
                    <p class="mt-1 text-xs text-slate-500">Usually the email address of the SMTP account.</p>
                </div>
                <div>
                    <label class="label" for="smtp-to">Recipient email (To)</label>
                    <input type="email" id="smtp-to" class="input" placeholder="recipient@example.com" data-to required>
                </div>
            </div>

            <div class="mt-4">
                <label class="label" for="smtp-subject">Subject <span class="font-normal text-slate-400">(optional)</span></label>
                <input type="text" id="smtp-subject" class="input" placeholder="SMTP test from Stunning Touch" data-subject maxlength="200">
            </div>

            <div class="mt-4">
                <label class="label" for="smtp-message">Message <span class="font-normal text-slate-400">(optional)</span></label>
                <textarea id="smtp-message" rows="4" class="input resize-y" placeholder="A custom message is optional — a default note is sent otherwise." data-message maxlength="5000"></textarea>
            </div>

            <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                <p class="font-semibold">This sends a real email.</p>
                <p class="mt-1">One message to one recipient per operation. SMTP acceptance means the server queued the message — it does not confirm final inbox delivery. Only test servers and mailboxes you are authorized to use.</p>
            </div>
        </div>

        {{-- Actions --}}
        <div class="mt-6 flex flex-wrap gap-2">
            <button type="button" class="btn-primary" data-submit-connection>Test Connection</button>
            <button type="button" class="btn-accent" data-submit-send>Send Test Email</button>
            <button type="button" class="btn-secondary" data-reset>Reset</button>
        </div>
    </form>

    {{-- Inline status --}}
    <div class="mt-6 hidden rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-600" data-status role="status"></div>

    {{-- Result panel --}}
    <div class="mt-6 hidden rounded-2xl border p-5" data-result>
        <p class="text-sm font-bold uppercase tracking-wide" data-result-title></p>
        <p class="mt-1 text-sm text-slate-700" data-result-message></p>

        <ul class="mt-3" data-steps></ul>

        <div class="mt-4 grid gap-3 sm:grid-cols-2" data-details></div>
    </div>

    {{-- Privacy notice --}}
    <div class="mt-8 rounded-xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm leading-relaxed text-slate-600" data-privacy hidden>
        <p class="font-semibold text-slate-800">How your credentials are handled</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            <li>Credentials are submitted to our server only to perform the exact operation you clicked — connection check or one test email.</li>
            <li>They are never stored on our servers, never written to your browser storage, and never logged.</li>
            <li>Password fields are cleared automatically after every operation.</li>
            <li>Outbound destinations are validated: private and internal network addresses are blocked.</li>
            <li>Requests are rate-limited to prevent abuse.</li>
        </ul>
    </div>
</div>
@endsection
