@extends('layouts.admin')

@section('title', 'AI Provider Settings')

@section('content')
    <div class="grid gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <div class="card mb-4 p-4 {{ $configurationState['configured'] && $configurationState['enabled'] ? 'border-emerald-200 bg-emerald-50' : ($configurationState['configured'] ? 'border-amber-200 bg-amber-50' : 'border-red-200 bg-red-50') }}">
                <div class="flex items-start gap-3">
                    <span class="mt-1 inline-block h-2.5 w-2.5 shrink-0 rounded-full {{ $configurationState['configured'] && $configurationState['enabled'] ? 'bg-emerald-500' : ($configurationState['configured'] ? 'bg-amber-500' : 'bg-red-500') }}"></span>
                    <div class="text-sm">
                        @if($configurationState['configured'] && $configurationState['enabled'])
                            <p class="font-semibold text-emerald-800">Configuration ready — the provider is enabled and fully configured.</p>
                        @elseif($configurationState['configured'])
                            <p class="font-semibold text-amber-800">Saved but disabled — tick “Enable AI content generation” and save to activate the provider.</p>
                        @else
                            <p class="font-semibold text-red-800">Incomplete configuration — missing: {{ implode(', ', $configurationState['missing']) }}.</p>
                            <p class="mt-1 text-red-700">Fill in the missing fields above and save. Bearer mode requires an API key; “None” is for keyless providers such as local Ollama.</p>
                            @unless($configurationState['enabled'])
                                <p class="mt-1 text-amber-800">The provider is also currently disabled — tick “Enable AI content generation” and save to activate it.</p>
                            @endunless
                        @endif
                        <p class="mt-1 text-xs text-slate-600">
                            Auth mode: {{ ucfirst($configurationState['auth_mode']) }} ·
                            {{ $configurationState['has_key'] ? 'API key stored (encrypted)' : 'no API key stored' }}
                        </p>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.ai-settings.update') }}" class="card p-6">
                @csrf
                @method('PUT')

                <h2 class="mb-1 font-bold text-slate-900">AI Content Provider</h2>
                <p class="mb-5 text-sm text-slate-600">Connect any OpenAI-compatible API (OpenAI, Azure OpenAI-compatible endpoints, OpenRouter, Groq, local LLMs, etc.). The API key is stored encrypted and never displayed again.</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="label" for="endpoint">API endpoint *</label>
                        <input id="endpoint" name="endpoint" type="url" value="{{ old('endpoint', $settings->endpoint) }}" class="input" placeholder="http://your-ollama-host:11434/v1">
                        <p class="mt-1 text-xs text-slate-500">OpenAI-compatible base URL ending at <code>/v1</code> (service appends <code>/chat/completions</code>) or a native Ollama URL ending at <code>/api/chat</code>.</p>
                    </div>
                    <div>
                        <label class="label" for="provider">Provider label</label>
                        <input id="provider" name="provider" value="{{ old('provider', $settings->provider) }}" class="input" maxlength="100">
                    </div>
                    <div class="sm:col-span-2" x-data="modelPicker({ initialModel: @js(old('model', $settings->model)), fetchUrl: @js(route('admin.ai-settings.models')) })" @click.outside="open = false">
                        <label class="label" for="model">Model *</label>
                        <div class="flex flex-wrap gap-2">
                            <div class="relative min-w-0 flex-1">
                                <input id="model" name="model" type="text" maxlength="100" autocomplete="off"
                                       x-model="query" @input="open = models.length > 0" @focus="open = models.length > 0"
                                       @keydown.arrow-down.prevent="open = filtered.length > 0" @keydown.escape="open = false"
                                       class="input" placeholder="qwen3.5:4b or click Fetch Available Models">
                                <div x-show="open && filtered.length > 0" x-cloak
                                     class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                                    <template x-for="m in filtered" :key="m">
                                        <button type="button" class="block w-full truncate px-3 py-2 text-left text-sm text-slate-800 hover:bg-slate-100"
                                                @click="select(m)" x-text="m"></button>
                                    </template>
                                </div>
                            </div>
                            <button type="button" class="btn-secondary shrink-0" @click="fetchModels()" :disabled="fetching || endpoint === ''">
                                <span x-show="!fetching">Fetch Available Models</span>
                                <span x-show="fetching" x-cloak>Fetching…</span>
                            </button>
                        </div>
                        <p x-show="message" x-cloak class="mt-1 text-xs" :class="messageOk ? 'text-emerald-600' : 'text-red-600'" x-text="message"></p>
                        <p class="mt-1 text-xs text-slate-500">Type a model ID manually (e.g. <code>qwen3.5:4b</code> for Ollama) or click “Fetch Available Models” to list the provider’s models. The currently saved model is kept if discovery fails.</p>
                    </div>
                    <div>
                        <label class="label" for="auth_mode">Authentication</label>
                        <select id="auth_mode" name="auth_mode" class="input">
                            @foreach(['bearer' => 'Bearer token (API key)', 'none' => 'None (local Ollama / proxy-authenticated)', 'basic' => 'Basic auth (reverse proxy)'] as $mode => $label)
                                <option value="{{ $mode }}" {{ old('auth_mode', $settings->auth_mode ?? 'bearer') === $mode ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="api_key">API key (bearer mode) {{ $settings->api_key ? '(stored — leave blank to keep)' : '' }}</label>
                        <input id="api_key" name="api_key" type="password" class="input" placeholder="{{ $settings->api_key ? '••••••••••••••••••••' : 'sk-…' }}" autocomplete="new-password">
                        @if($settings->api_key)
                            <p class="mt-1 text-xs text-emerald-600">✓ A key is saved (encrypted). Enter a new key only to replace it.</p>
                        @endif
                    </div>
                    <div>
                        <label class="label" for="basic_auth_user">Basic auth user</label>
                        <input id="basic_auth_user" name="basic_auth_user" value="{{ old('basic_auth_user', $settings->basic_auth_user) }}" class="input" autocomplete="off">
                    </div>
                    <div>
                        <label class="label" for="basic_auth_password">Basic auth password {{ $settings->basic_auth_password ? '(stored — leave blank to keep)' : '' }}</label>
                        <input id="basic_auth_password" name="basic_auth_password" type="password" class="input" autocomplete="new-password">
                    </div>
                    <div>
                        <label class="label" for="temperature">Temperature (0–2, optional)</label>
                        <input id="temperature" name="temperature" type="number" step="0.05" min="0" max="2" value="{{ old('temperature', $settings->temperature) }}" class="input">
                    </div>
                    <div>
                        <label class="label" for="max_tokens">Max output tokens (optional)</label>
                        <input id="max_tokens" name="max_tokens" type="number" min="100" max="32000" value="{{ old('max_tokens', $settings->max_tokens) }}" class="input">
                    </div>
                    <div>
                        <label class="label" for="context_length">Context length (tokens)</label>
                        <input id="context_length" name="context_length" type="number" min="512" max="131072" step="512" value="{{ old('context_length', $settings->context_length ?? 16384) }}" class="input">
                        <p class="mt-1 text-xs text-slate-500">Sent as <code>num_ctx</code> to Ollama native endpoints. Default 16384; verify RAM before raising.</p>
                    </div>
                    <div>
                        <label class="label" for="timeout_seconds">Request timeout (seconds)</label>
                        <input id="timeout_seconds" name="timeout_seconds" type="number" min="30" max="3600" value="{{ old('timeout_seconds', $settings->timeout_seconds ?? 900) }}" class="input">
                        <p class="mt-1 text-xs text-slate-500">Long generations on CPU can take several minutes.</p>
                    </div>
                    <div>
                        <label class="label" for="connect_timeout_seconds">Connect timeout (seconds)</label>
                        <input id="connect_timeout_seconds" name="connect_timeout_seconds" type="number" min="2" max="120" value="{{ old('connect_timeout_seconds', $settings->connect_timeout_seconds ?? 15) }}" class="input">
                    </div>
                    <div>
                        <label class="label" for="max_retries">Retries on transient errors</label>
                        <input id="max_retries" name="max_retries" type="number" min="0" max="5" value="{{ old('max_retries', $settings->max_retries ?? 2) }}" class="input">
                        <p class="mt-1 text-xs text-slate-500">Retries use exponential backoff on 429 and 5xx responses and connection failures.</p>
                    </div>
                    <div>
                        <label class="label" for="retry_backoff_ms">Retry backoff base (ms)</label>
                        <input id="retry_backoff_ms" name="retry_backoff_ms" type="number" min="0" max="60000" step="100" value="{{ old('retry_backoff_ms', $settings->retry_backoff_ms ?? 2000) }}" class="input">
                    </div>
                    <div>
                        <label class="label" for="keep_alive">Ollama keep-alive</label>
                        <input id="keep_alive" name="keep_alive" type="text" value="{{ old('keep_alive', $settings->keep_alive ?? '10m') }}" class="input" placeholder="10m">
                        <p class="mt-1 text-xs text-slate-500">How long Ollama keeps the model in RAM (native endpoints only).</p>
                    </div>
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700 sm:col-span-2">
                        <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('is_active', $settings->is_active) ? 'checked' : '' }}>
                        Enable AI content generation
                    </label>
                </div>

                <div class="mt-6 flex flex-wrap gap-3">
                    <button type="submit" class="btn-accent">Save Settings</button>
                </div>
            </form>

            <div class="mt-4 flex flex-wrap gap-3">
                <form method="POST" action="{{ route('admin.ai-settings.test') }}">
                    @csrf
                    <button type="submit" class="btn-secondary">Test Connection</button>
                </form>
                <form method="POST" action="{{ route('admin.ai-settings.model-check') }}">
                    @csrf
                    <button type="submit" class="btn-secondary">Check Model Availability</button>
                </form>
                <form method="POST" action="{{ route('admin.ai-settings.generate') }}" onsubmit="return confirm('Generate a new AI blog draft now? It will be saved as a draft for review.')">
                    @csrf
                    <button type="submit" class="btn-secondary">Generate Draft Now</button>
                </form>
            </div>
        </div>

        <div class="card h-fit overflow-hidden">
            <h2 class="border-b border-slate-100 px-5 py-4 font-bold text-slate-900">Recent Generations</h2>
            @if($generations->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500">No generations yet.</p>
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach($generations as $generation)
                        <li class="px-5 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <p class="truncate text-slate-800">{{ $generation->topic ?? '—' }}</p>
                                <span class="{{ match ($generation->status) { 'completed' => 'badge-green', 'failed' => 'badge-red', default => 'badge-amber' } }}">{{ ucfirst($generation->status) }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $generation->created_at->format('M j, Y H:i') }}
                                @if($generation->blog_post_id)
                                    · <a href="{{ route('admin.blog.posts.edit', $generation->blog_post_id) }}" class="text-brand-600">Open draft</a>
                                @endif
                            </p>
                            @if($generation->error_message)
                                <p class="mt-1 text-xs text-red-600">{{ \Illuminate\Support\Str::limit($generation->error_message, 160) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('modelPicker', (config) => ({
                query: config.initialModel || '',
                models: [],
                open: false,
                fetching: false,
                message: '',
                messageOk: false,
                endpoint: '',

                init() {
                    const endpointInput = document.getElementById('endpoint');
                    this.endpoint = (endpointInput ? endpointInput.value : '').trim();
                    if (endpointInput) {
                        endpointInput.addEventListener('input', (e) => {
                            this.endpoint = e.target.value.trim();
                        });
                    }
                },

                get filtered() {
                    const q = this.query.trim().toLowerCase();
                    if (q === '') return this.models;

                    return this.models.filter((m) => m.toLowerCase().includes(q));
                },

                select(model) {
                    this.query = model;
                    this.open = false;
                },

                async fetchModels() {
                    if (this.fetching) return;

                    this.fetching = true;
                    this.message = '';
                    this.open = false;

                    // Only credentials typed in this session are sent; the
                    // server falls back to the stored encrypted key when a
                    // field is blank. Nothing here ever reads or echoes the
                    // stored key itself.
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                    const apiKey = document.getElementById('api_key')?.value || '';
                    const authMode = document.getElementById('auth_mode')?.value || '';
                    const basicUser = document.getElementById('basic_auth_user')?.value || '';
                    const basicPassword = document.getElementById('basic_auth_password')?.value || '';

                    try {
                        const response = await fetch(config.fetchUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                            },
                            body: JSON.stringify({
                                endpoint: this.endpoint,
                                api_key: apiKey,
                                auth_mode: authMode,
                                basic_auth_user: basicUser,
                                basic_auth_password: basicPassword,
                            }),
                        });

                        const data = await response.json();

                        this.messageOk = Boolean(data.success);
                        this.message = data.message || (data.success ? 'Models retrieved.' : 'Model discovery failed.');

                        if (data.success && Array.isArray(data.models) && data.models.length > 0) {
                            this.models = data.models;
                            this.open = true;
                        }
                        // On failure: keep both the current model text and any
                        // previously fetched list untouched.
                    } catch (error) {
                        this.messageOk = false;
                        this.message = 'Model discovery request failed: network or server error.';
                    } finally {
                        this.fetching = false;
                    }
                },
            }));
        });
    </script>
@endsection
