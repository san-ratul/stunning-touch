@extends('layouts.admin')

@section('title', 'Artisan Command Runner')

@section('content')
    <div class="mx-auto max-w-4xl space-y-6" data-artisan-runner>

        <div class="card p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Run an approved Artisan command</h2>
                    <p class="mt-1 text-sm text-slate-500">Only the commands listed under “Allowed commands” can be executed. Commands run as <code class="rounded bg-slate-100 px-1 font-mono text-xs">php artisan …</code> in the project root — no shell is involved.</p>
                </div>
            </div>

            @if($environmentProblem)
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="alert">
                    <p class="font-semibold">Command execution is not available on this server</p>
                    <p class="mt-1">{{ $environmentProblem }}</p>
                </div>
            @endif

            <div class="mt-4">
                <label class="label" for="artisan-command">Command</label>
                <textarea id="artisan-command" rows="2" class="input font-mono text-sm" maxlength="500"
                          placeholder="php artisan db:seed --class=DatabaseSeeder --force" data-command-input spellcheck="false"></textarea>
                <p class="mt-1 text-xs text-slate-500">Accepts <code class="font-mono">php artisan …</code>, <code class="font-mono">artisan …</code> or the bare command name. Maximum 500 characters.</p>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" class="btn-accent" data-run-button>Run Command</button>
                <button type="button" class="btn-secondary" data-clear-button>Clear</button>
            </div>

            <div class="mt-4 flex items-center gap-2 text-sm text-slate-500" data-loading style="display:none">
                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                </svg>
                Running command… this can take up to 2 minutes.
            </div>

            <div class="mt-4 rounded-xl border px-4 py-3 text-sm font-medium" role="alert" data-error hidden></div>
        </div>

        {{-- Result --}}
        <div class="card p-6" data-result hidden>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide" data-result-status></p>
                    <p class="mt-1 break-all font-mono text-sm font-bold text-slate-800" data-result-command></p>
                </div>
                <button type="button" class="btn-secondary px-3 py-1.5 text-xs" data-copy-button>Copy output</button>
            </div>

            <p class="mt-2 text-sm" data-result-message></p>

            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3 text-center">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Exit code</p>
                    <p class="mt-0.5 text-lg font-extrabold tabular-nums text-slate-800" data-result-exit>—</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3 text-center">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Duration</p>
                    <p class="mt-0.5 text-lg font-extrabold tabular-nums text-slate-800" data-result-duration>—</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3 text-center">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Status</p>
                    <p class="mt-0.5 text-lg font-extrabold text-slate-800" data-result-state>—</p>
                </div>
            </div>

            <div class="mt-4" data-stdout-section hidden>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Standard output</p>
                <pre class="mt-1 max-h-96 overflow-auto rounded-xl bg-slate-900 p-4 font-mono text-xs leading-relaxed text-slate-100" data-result-stdout></pre>
            </div>

            <div class="mt-4" data-stderr-section hidden>
                <p class="text-xs font-semibold uppercase tracking-wide text-rose-600">Error output</p>
                <pre class="mt-1 max-h-64 overflow-auto rounded-xl bg-rose-950 p-4 font-mono text-xs leading-relaxed text-rose-100" data-result-stderr></pre>
            </div>
        </div>

        {{-- Allowed commands --}}
        <div class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Allowed commands</h2>
            <p class="mt-1 text-sm text-slate-500">Anything outside this list is rejected before execution. New commands must be added explicitly to the server-side allowlist with their own argument schema.</p>

            <div class="mt-4 overflow-hidden rounded-xl border border-slate-200">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">Command</th>
                            <th scope="col" class="px-4 py-2.5">Description</th>
                            <th scope="col" class="px-4 py-2.5">Options</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($catalog as $item)
                            <tr>
                                <td class="px-4 py-2.5 font-mono text-xs font-bold text-brand-700">{{ $item['name'] }}</td>
                                <td class="px-4 py-2.5 text-slate-600">{{ $item['description'] }}</td>
                                <td class="px-4 py-2.5 font-mono text-xs text-slate-500">
                                    @if(count($item['options']) === 0)
                                        —
                                    @else
                                        {{ implode('  ', $item['options']) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- History --}}
        <div class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Recent executions</h2>
            @if($history->isEmpty())
                <p class="mt-2 text-sm text-slate-500">No commands have been run yet.</p>
            @else
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach($history as $entry)
                        <li class="flex flex-wrap items-baseline gap-x-3 py-2 text-sm">
                            <span class="font-mono text-xs font-bold text-slate-700">{{ $entry->description }}</span>
                            <span class="text-xs text-slate-400">{{ $entry->created_at?->format('Y-m-d H:i:s') }}</span>
                            <span class="text-xs text-slate-500">exit {{ $entry->properties['exit_code'] ?? '—' }} · {{ $entry->properties['duration_ms'] ?? '—' }} ms</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @push('scripts')
    <script>
        (function () {
            const root = document.querySelector('[data-artisan-runner]');
            if (!root) return;

            const input = root.querySelector('[data-command-input]');
            const runButton = root.querySelector('[data-run-button]');
            const clearButton = root.querySelector('[data-clear-button]');
            const copyButton = root.querySelector('[data-copy-button]');
            const loading = root.querySelector('[data-loading]');
            const errorBox = root.querySelector('[data-error]');
            const resultPanel = root.querySelector('[data-result]');
            const statusNode = root.querySelector('[data-result-status]');
            const commandNode = root.querySelector('[data-result-command]');
            const messageNode = root.querySelector('[data-result-message]');
            const exitNode = root.querySelector('[data-result-exit]');
            const durationNode = root.querySelector('[data-result-duration]');
            const stateNode = root.querySelector('[data-result-state]');
            const stdoutSection = root.querySelector('[data-stdout-section]');
            const stderrSection = root.querySelector('[data-stderr-section]');
            const stdoutNode = root.querySelector('[data-result-stdout]');
            const stderrNode = root.querySelector('[data-result-stderr]');

            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            let busy = false;

            runButton.addEventListener('click', run);
            clearButton.addEventListener('click', () => {
                input.value = '';
                hide(errorBox);
                resultPanel.hidden = true;
                input.focus();
            });

            copyButton.addEventListener('click', async () => {
                const text = (stdoutNode.textContent || '') + (stderrNode.textContent ? '\n' + stderrNode.textContent : '');
                await navigator.clipboard.writeText(text);
                const original = copyButton.textContent;
                copyButton.textContent = 'Copied!';
                setTimeout(() => { copyButton.textContent = original; }, 1200);
            });

            async function run() {
                if (busy) return;

                const command = input.value.trim();

                if (command === '') {
                    showError('Enter a command to run.');
                    return;
                }

                // Confirmation for commands that modify data.
                if (/db:seed/.test(command) && !window.confirm('db:seed can modify database content. Run it now?')) {
                    return;
                }

                busy = true;
                runButton.disabled = true;
                loading.style.display = 'flex';
                hide(errorBox);
                resultPanel.hidden = true;

                try {
                    const response = await fetch('{{ route("admin.artisan-runner.run") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ command }),
                    });

                    if (response.status === 403) {
                        showError('You do not have permission to run Artisan commands.');
                        return;
                    }

                    if (response.status === 429) {
                        showError('Too many requests — please wait a minute.');
                        return;
                    }

                    const data = await response.json().catch(() => null);
                    if (!data || !data.result) {
                        showError('Unexpected response from the server.');
                        return;
                    }

                    render(data.result);
                } catch {
                    showError('The request could not be sent. Check your connection and try again.');
                } finally {
                    busy = false;
                    runButton.disabled = false;
                    loading.style.display = 'none';
                }
            }

            function render(result) {
                hide(errorBox);
                resultPanel.hidden = false;

                const state = { success: 'Success', failed: 'Failed', timeout: 'Timed out', blocked: 'Not available', rejected: 'Rejected' }[result.status] || result.status;
                const colorClass = { success: 'text-emerald-700', failed: 'text-rose-700', timeout: 'text-amber-700', blocked: 'text-amber-700', rejected: 'text-rose-700' }[result.status] || 'text-slate-700';

                statusNode.textContent = 'Execution ' + state.toLowerCase();
                statusNode.className = 'text-xs font-semibold uppercase tracking-wide ' + colorClass;

                commandNode.textContent = result.command || '';
                messageNode.textContent = result.message || '';

                exitNode.textContent = result.exit_code === null || result.exit_code === undefined ? '—' : String(result.exit_code);
                durationNode.textContent = (result.duration_ms ?? 0) + ' ms';
                stateNode.textContent = state;

                stdoutNode.textContent = result.stdout || '';
                stderrNode.textContent = result.stderr || '';
                stdoutSection.hidden = !result.stdout;
                stderrSection.hidden = !result.stderr;

                if (result.output_truncated) {
                    const note = document.createElement('p');
                    note.className = 'mt-1 text-xs text-amber-600';
                    note.textContent = 'Output was truncated at the 100 KB safety limit.';
                    stdoutSection.appendChild(note);
                }
            }

            function showError(message) {
                resultPanel.hidden = true;
                errorBox.textContent = message;
                errorBox.hidden = false;
                errorBox.classList.add('border-rose-200', 'bg-rose-50', 'text-rose-700');
            }

            function hide(node) {
                node.hidden = true;
            }
        })();
    </script>
    @endpush
@endsection
