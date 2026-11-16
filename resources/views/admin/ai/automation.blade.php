@extends('layouts.admin')

@section('title', 'Blog Automation')

@section('content')
    <form method="POST" action="{{ route('admin.automation.update') }}" class="grid max-w-4xl gap-6 md:grid-cols-2">
        @csrf
        @method('PUT')

        <div class="card p-6 md:col-span-2">
            <h2 class="mb-1 font-bold text-slate-900">Daily AI Content Automation</h2>
            <p class="mb-5 text-sm text-slate-600">
                When enabled, the scheduled command <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">blog:generate-daily</code>
                creates one AI draft per run. Drafts always require admin review unless auto-publish is explicitly enabled below.
                Requires the cron job to be configured on your hosting — see <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">.docs/CRON_SETUP.md</code>.
            </p>
            @if(!$automation->last_run_at && $automation->is_enabled)
                <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Automation is enabled but has not run yet. Make sure the cron job is installed on the server.
                </p>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700 sm:col-span-2">
                    <input type="checkbox" name="is_enabled" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('is_enabled', $automation->is_enabled) ? 'checked' : '' }}>
                    Enable automated AI draft generation
                </label>
                <div>
                    <label class="label" for="frequency">Frequency</label>
                    <select id="frequency" name="frequency" class="input">
                        <option value="daily" {{ old('frequency', $automation->frequency) === 'daily' ? 'selected' : '' }}>Daily</option>
                        <option value="weekly" {{ old('frequency', $automation->frequency) === 'weekly' ? 'selected' : '' }}>Weekly (Mondays)</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="preferred_time">Preferred time</label>
                    <input id="preferred_time" type="time" name="preferred_time" value="{{ old('preferred_time', $automation->preferred_time) }}" class="input">
                </div>
                <div>
                    <label class="label" for="timezone">Timezone</label>
                    <select id="timezone" name="timezone" class="input">
                        @foreach(['Asia/Dhaka', 'UTC', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Europe/London', 'Europe/Berlin', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Singapore', 'Australia/Sydney'] as $tz)
                            <option value="{{ $tz }}" {{ old('timezone', $automation->timezone) === $tz ? 'selected' : '' }}>{{ $tz }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label">Last run</label>
                    <p class="pt-2 text-sm text-slate-600">{{ $automation->last_run_at?->format('M j, Y H:i T') ?? 'Never' }}</p>
                </div>
                <div>
                    <label class="label" for="featured_image_mode">Featured image mode</label>
                    <select id="featured_image_mode" name="featured_image_mode" class="input">
                        @foreach(['none' => 'No featured image', 'branded' => 'Branded cover (generated locally)', 'cloudflare' => 'Cloudflare Workers AI (falls back to local cover)', 'external_api' => 'External image API (if configured)'] as $mode => $label)
                            <option value="{{ $mode }}" {{ old('featured_image_mode', $automation->featured_image_mode ?? 'branded') === $mode ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">This is the switch that decides which image provider generates covers. Saving image settings below with Cloudflare or an external API selected switches this mode automatically.</p>
                </div>
                <div>
                    <label class="label" for="posts_per_day">Posts per day (max 4)</label>
                    <input id="posts_per_day" name="posts_per_day" type="number" min="1" max="4" value="{{ old('posts_per_day', $automation->posts_per_day ?? 3) }}" class="input">
                    <p class="mt-1 text-xs text-slate-500">Quality gates may publish fewer rather than lowering standards.</p>
                </div>
                <div>
                    <label class="label" for="publish_slots">Publishing slots (comma-separated, max 4)</label>
                    <input id="publish_slots" name="publish_slots" value="{{ old('publish_slots', $automation->publish_slots ? implode(', ', $automation->publish_slots) : '06:00, 12:00, 18:00, 21:00') }}" class="input" placeholder="06:00, 12:00, 18:00, 21:00">
                </div>
                <div>
                    <label class="label" for="min_word_count">Minimum word count</label>
                    <input id="min_word_count" name="min_word_count" type="number" min="200" max="5000" value="{{ old('min_word_count', $automation->min_word_count ?? 900) }}" class="input">
                </div>
                <div>
                    <label class="label" for="max_word_count">Maximum word count</label>
                    <input id="max_word_count" name="max_word_count" type="number" min="200" max="10000" value="{{ old('max_word_count', $automation->max_word_count ?? 2000) }}" class="input">
                </div>
                <div>
                    <label class="label" for="duplicate_threshold">Duplicate threshold (%)</label>
                    <input id="duplicate_threshold" name="duplicate_threshold" type="number" min="50" max="100" value="{{ old('duplicate_threshold', $automation->duplicate_threshold ?? 85) }}" class="input">
                    <p class="mt-1 text-xs text-slate-500">Topics matching an existing post above this similarity are skipped.</p>
                </div>
                <div>
                    <label class="label" for="max_concurrent_generations">Max concurrent generations</label>
                    <input id="max_concurrent_generations" name="max_concurrent_generations" type="number" min="1" max="4" value="{{ old('max_concurrent_generations', $automation->max_concurrent_generations ?? 1) }}" class="input">
                    <p class="mt-1 text-xs text-slate-500">Keep at 1 on 12 GB ARM instances — all AI calls already run sequentially.</p>
                </div>
                <div>
                    <label class="label" for="max_generation_continuations">Max continuation calls for short drafts</label>
                    <input id="max_generation_continuations" name="max_generation_continuations" type="number" min="0" max="3" value="{{ old('max_generation_continuations', $automation->max_generation_continuations ?? 2) }}" class="input">
                    <p class="mt-1 text-xs text-slate-500">When a draft is shorter than the minimum word count, up to this many extra AI calls write additional sections. 0 disables continuation.</p>
                </div>
                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700 sm:col-span-2">
                    <input type="checkbox" name="research_enabled" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('research_enabled', $automation->research_enabled ?? true) ? 'checked' : '' }}>
                    Research topics before generation (mandatory-source checks apply to auto-publishing)
                </label>
            </div>
        </div>

        <div class="card p-6">
            <h2 class="mb-4 font-bold text-slate-900">Content Preferences</h2>
            <div class="space-y-4">
                <div>
                    <label class="label" for="topics">Preferred topics (one per line)</label>
                    <textarea id="topics" name="topics" rows="6" class="input" placeholder="How to compress images without losing quality{{ "\n" }}5 ways to merge PDFs online">{{ old('topics', $automation->topics ? implode("\n", $automation->topics) : '') }}</textarea>
                </div>
                <div>
                    <label class="label" for="keywords">Keyword preferences (comma-separated)</label>
                    <input id="keywords" name="keywords" value="{{ old('keywords', $automation->keywords) }}" class="input" maxlength="500">
                </div>
                <div>
                    <label class="label">Target blog categories</label>
                    <div class="max-h-40 space-y-1.5 overflow-y-auto rounded-xl border border-slate-200 p-3">
                        @forelse($blogCategories as $category)
                            <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                                <input type="checkbox" name="categories[]" value="{{ $category->id }}" class="h-3.5 w-3.5 rounded accent-brand-600" {{ in_array($category->id, old('categories', $automation->categories ?? [])) ? 'checked' : '' }}>
                                {{ $category->name }}
                            </label>
                        @empty
                            <p class="text-sm text-slate-500">No blog categories yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="card p-6">
            <h2 class="mb-4 font-bold text-slate-900">Safety</h2>
            <label class="flex cursor-pointer items-start gap-3 text-sm text-slate-700">
                <input type="checkbox" name="auto_publish" value="1" class="mt-0.5 h-4 w-4 rounded accent-brand-600" {{ old('auto_publish', $automation->auto_publish) ? 'checked' : '' }}>
                <span>
                    <span class="font-medium">Auto-publish generated articles</span>
                    <span class="mt-1 block text-xs leading-relaxed text-slate-500">
                        Off by default — recommended. Generated drafts are saved for human review; nothing is published without an admin checking it.
                        Enabling this publishes AI output automatically <strong>only when it passes all mandatory quality checks</strong> (length, SEO metadata, headings, HTML validity, duplicates); anything else stays a draft.
                    </span>
                </span>
            </label>
            <div class="mt-6">
                <button type="submit" class="btn-accent w-full">Save Automation Settings</button>
            </div>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.image-settings.update') }}" class="card mt-6 max-w-4xl p-6">
        @csrf
        @method('PUT')

        <h2 class="mb-1 font-bold text-slate-900">Featured Image Providers</h2>
        <p class="mb-5 text-sm text-slate-600">
            Selecting a provider here and saving switches the featured image mode above to match it.
            Cloudflare Workers AI (FLUX.1 [schnell]) and any OpenAI-images-compatible endpoint are supported;
            on failure generation falls back to the local branded cover. The API key is stored encrypted and never displayed.
            Locally generated branded covers require no configuration and are the recommended default.
        </p>

        @php($imageSettings = \App\Models\ImageSetting::current())

        <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="image_provider">Featured image provider</label>
                    <select id="image_provider" name="provider" class="input">
                        <option value="none" {{ old('provider', $imageSettings->provider) === 'none' ? 'selected' : '' }}>None (local covers only)</option>
                        <option value="openai-images" {{ old('provider', $imageSettings->provider) === 'openai-images' ? 'selected' : '' }}>OpenAI-images-compatible API</option>
                        <option value="cloudflare" {{ old('provider', $imageSettings->provider) === 'cloudflare' ? 'selected' : '' }}>Cloudflare Workers AI (FLUX.1 [schnell])</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Saving with Cloudflare or an external API selected switches the featured image mode to that provider.</p>
                </div>
            <div>
                <label class="label" for="image_endpoint">Endpoint (OpenAI-images only)</label>
                <input id="image_endpoint" name="endpoint" type="url" value="{{ old('endpoint', $imageSettings->endpoint) }}" class="input" placeholder="https://api.openai.com/v1">
            </div>
            <div class="sm:col-span-2">
                <label class="label" for="image_api_key">API token {{ $imageSettings->api_key ? '(stored — leave blank to keep)' : '*' }}</label>
                <input id="image_api_key" name="api_key" type="password" class="input" autocomplete="new-password">
                <p class="mt-1 text-xs text-slate-500">
                    @if($imageSettings->api_key)
                        ✓ A token is saved (encrypted). Enter a new token only to replace it.
                    @else
                        Cloudflare: create a token with Workers AI permissions (Account → Workers AI → Edit). Stored encrypted; never displayed or logged.
                    @endif
                </p>
            </div>
            <div>
                <label class="label" for="image_account_id">Cloudflare Account ID (32 hex chars)</label>
                <input id="image_account_id" name="account_id" value="{{ old('account_id', $imageSettings->account_id) }}" class="input" maxlength="100" placeholder="e.g. 023e105f4ecef8ad9ca31a8372d0c353">
                @error('account_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="image_model">Model (Cloudflare)</label>
                <input id="image_model" name="model" value="{{ old('model', $imageSettings->model) }}" class="input" maxlength="100" placeholder="@cf/black-forest-labs/flux-1-schnell">
                <p class="mt-1 text-xs text-slate-500">The URL is constructed server-side from this ID; arbitrary endpoints are never accepted.</p>
                @error('model')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="image_steps">Inference steps (1–8)</label>
                <input id="image_steps" name="steps" type="number" min="1" max="8" value="{{ old('steps', $imageSettings->steps ?? 4) }}" class="input">
            </div>
            <div>
                <label class="label" for="image_daily_limit">Daily image limit (UTC quota day)</label>
                <input id="image_daily_limit" name="daily_limit" type="number" min="0" max="50" value="{{ old('daily_limit', $imageSettings->daily_limit ?? 4) }}" class="input">
                <p class="mt-1 text-xs text-slate-500">Successful generations count against the limit; failures are tracked separately.</p>
            </div>
            <div>
                <label class="label" for="image_width">Image width × height (px, for monitoring display)</label>
                <div class="flex gap-2">
                    <input id="image_width" name="width" type="number" min="256" max="2048" value="{{ old('width', $imageSettings->width ?? 1024) }}" class="input">
                    <span class="pt-2 text-slate-400">×</span>
                    <input name="height" type="number" min="256" max="2048" value="{{ old('height', $imageSettings->height ?? 1024) }}" class="input" aria-label="Image height">
                </div>
                <p class="mt-1 text-xs text-slate-500">FLUX.1 [schnell] generates its native size; the app center-crops to 1200×630 locally without distortion.</p>
            </div>
            <div>
                <label class="label" for="image_max_retries">Retries (transient errors)</label>
                <input id="image_max_retries" name="max_retries" type="number" min="0" max="5" value="{{ old('max_retries', $imageSettings->max_retries ?? 2) }}" class="input">
            </div>
            <div>
                <label class="label" for="image_timeout">Request timeout (seconds)</label>
                <input id="image_timeout" name="timeout_seconds" type="number" min="10" max="600" value="{{ old('timeout_seconds', $imageSettings->timeout_seconds ?? 120) }}" class="input">
            </div>
            <div>
                <label class="label" for="image_size">Size (OpenAI-images only)</label>
                <select id="image_size" name="size" class="input">
                    @foreach(['1792x1024' => '1792×1024 (landscape)', '1024x1024' => '1024×1024 (square)', '1024x1792' => '1024×1792 (portrait)'] as $size => $label)
                        <option value="{{ $size }}" {{ old('size', $imageSettings->size) === $size ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            <button type="submit" class="btn-secondary">Save Image Settings</button>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.image-settings.test') }}" class="mt-4 flex flex-wrap gap-3">
        @csrf
        <button type="submit" class="btn-secondary">Test Cloudflare Connection</button>
    </form>
    <form method="POST" action="{{ route('admin.image-settings.test-generate') }}" class="mt-2" onsubmit="return confirm('Generate one test image using your Cloudflare quota?')">
        @csrf
        <button type="submit" class="btn-secondary">Test Image Generation</button>
    </form>
@endsection
