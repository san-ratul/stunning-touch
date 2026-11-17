@extends('layouts.admin')

@section('title', 'Bot Protection')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">
            Application-managed bot detection, blocking and analytics. Detection and enforcement are measured separately — a detected bot is not automatically a blocked bot.
        </p>
        <div class="flex gap-1 rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-slate-800">
            @foreach(['24h' => 'Last 24 hours', '7d' => '7 Days', '30d' => '30 Days'] as $key => $label)
                <a href="{{ route('admin.bot-protection.index', ['range' => $key]) }}"
                   class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $range === $key ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach([
            ['Total Bot Requests', number_format($summary['total_bot']), 'Requests classified as automated (verified, known or suspicious).'],
            ['Blocked Requests', number_format($summary['blocked']), 'Requests actually rejected by this application (403 / honeypot).'],
            ['Allowed Bot Requests', number_format($summary['allowed_bots']), 'Automated requests intentionally permitted (e.g. verified crawlers reading pages).'],
            ['Rate-Limited Requests', number_format($summary['rate_limited']), 'Requests rejected because a configured rate limit was exceeded (429).'],
            ['Suspicious Requests', number_format($summary['suspicious']), 'Category total (overlaps with outcome totals above).'],
            ['Unknown Traffic', number_format($summary['unknown']), 'Unclassified traffic on monitored endpoints — never counted as human.'],
            ['Verified Search Bots', number_format($summary['verified']), 'Reverse-DNS-verified crawler requests.'],
            ['Duplicate Events Rejected', number_format($summary['duplicates']), 'Duplicate analytics beacons rejected by deduplication.'],
        ] as [$label, $value, $hint])
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-extrabold tabular-nums text-slate-900">{{ $value }}</p>
                <p class="mt-1 text-[11px] leading-snug text-slate-400">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 card p-5">
        <h2 class="font-bold text-slate-900">Automated traffic over time</h2>
        <p class="text-xs text-slate-500">Values are requests observed by Laravel per {{ $range === '24h' ? 'hour' : 'day' }}.</p>
        <div class="mt-4 h-72"><canvas id="botTimeline" aria-label="Automated traffic over time" role="img"></canvas></div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="card p-5">
            <h2 class="font-bold text-slate-900">Blocked vs allowed bot requests</h2>
            <p class="text-xs text-slate-500">Requests per {{ $range === '24h' ? 'hour' : 'day' }}.</p>
            <div class="mt-4 h-64"><canvas id="outcomeChart" aria-label="Blocked versus allowed bot requests" role="img"></canvas></div>
        </div>
        <div class="card p-5">
            <h2 class="font-bold text-slate-900">Human vs automated traffic</h2>
            <p class="text-xs text-slate-500">Requests: human page views (existing visitor analytics) vs detected automated + unknown requests.</p>
            <div class="mx-auto mt-4 h-64"><canvas id="humanChart" aria-label="Human versus automated traffic" role="img"></canvas></div>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="card overflow-hidden">
            <h2 class="border-b border-slate-100 px-4 py-3 font-bold text-slate-900">Bot categories observed</h2>
            @if(count($categories) === 0)
                <p class="px-4 py-8 text-center text-sm text-slate-500">No automated traffic recorded yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[420px] text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Category</th>
                                <th class="px-4 py-3 font-semibold">Family</th>
                                <th class="px-4 py-3 text-right font-semibold">Requests</th>
                                <th class="px-4 py-3 text-right font-semibold">Blocked</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($categories as $row)
                                <tr>
                                    <td class="px-4 py-2.5 font-medium text-slate-700">{{ str_replace('_', ' ', $row->category) }}</td>
                                    <td class="px-4 py-2.5 text-slate-600">{{ $row->bot_name ?: '—' }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ number_format($row->requests) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ number_format($row->blocked) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="card overflow-hidden">
            <h2 class="border-b border-slate-100 px-4 py-3 font-bold text-slate-900">Top targeted endpoints</h2>
            @if(count($topEndpoints) === 0)
                <p class="px-4 py-8 text-center text-sm text-slate-500">No data yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[380px] text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Endpoint group</th>
                                <th class="px-4 py-3 text-right font-semibold">Requests</th>
                                <th class="px-4 py-3 text-right font-semibold">Blocked</th>
                                <th class="px-4 py-3 text-right font-semibold">Rate-limited</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($topEndpoints as $row)
                                <tr>
                                    <td class="px-4 py-2.5 font-medium text-slate-700">{{ $row->endpoint_group }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ number_format($row->requests) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ number_format($row->blocked) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ number_format($row->rate_limited) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card mt-6 overflow-hidden">
        <h2 class="border-b border-slate-100 px-4 py-3 font-bold text-slate-900">Recent security activity</h2>
        <p class="px-4 py-2 text-xs text-slate-500">Aggregated rejections (hourly buckets). No raw IPs, request bodies or credentials are ever stored.</p>
        @if(count($recentEvents) === 0)
            <p class="px-4 py-8 text-center text-sm text-slate-500">No security events recorded yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Timestamp</th>
                            <th class="px-4 py-3 font-semibold">Category</th>
                            <th class="px-4 py-3 font-semibold">Family</th>
                            <th class="px-4 py-3 font-semibold">Endpoint</th>
                            <th class="px-4 py-3 font-semibold">Action</th>
                            <th class="px-4 py-3 font-semibold">Reason</th>
                            <th class="px-4 py-3 text-right font-semibold">Count</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($recentEvents as $row)
                            <tr>
                                <td class="px-4 py-2.5 whitespace-nowrap text-slate-600">{{ $row->date.' '.sprintf('%02d', $row->hour).':00' }}</td>
                                <td class="px-4 py-2.5 font-medium text-slate-700">{{ str_replace('_', ' ', $row->category) }}</td>
                                <td class="px-4 py-2.5 text-slate-600">{{ $row->bot_name ?: '—' }}</td>
                                <td class="px-4 py-2.5 text-slate-600">{{ $row->endpoint_group }}</td>
                                <td class="px-4 py-2.5 text-slate-600">{{ str_replace('_', ' ', $row->action) }}</td>
                                <td class="px-4 py-2.5 text-slate-500">{{ $row->reason }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ number_format($row->requests) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card mt-6 border-amber-200 bg-amber-50/60 p-5 dark:border-amber-800/60 dark:bg-amber-900/10">
        <h2 class="font-bold text-slate-900">Measurement scope</h2>
        <p class="mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
            These metrics cover <strong>application-level</strong> traffic only — requests that reached Laravel.
            Requests rejected earlier by the hosting layer (.htaccess / ModSecurity / Apache / the web server) die
            before PHP executes and are <strong>not measurable from this application</strong>; they are not estimated
            or included anywhere on this page. Check the hosting control panel (Raw Access Logs, ModSecurity logs)
            for that layer.
        </p>
    </div>

    <div class="card mt-6 p-5">
        <h2 class="font-bold text-slate-900">Protection settings</h2>
        <p class="text-xs text-slate-500">Only authorized administrators can change these. Server-side policy cannot be overridden by clients.</p>

        <form method="POST" action="{{ route('admin.bot-protection.settings') }}" class="mt-4 max-w-2xl space-y-4"
              x-data="{
                  enabled: {{ $settings['enabled'] ? 'true' : 'false' }},
                  confirmDisable: false,
                  submit(e) {
                      if (this.enabled === false && ! confirm('Disable bot protection entirely? This removes automated-traffic detection and blocking for every public endpoint.')) {
                          e.preventDefault();
                          return;
                      }
                  },
              }">
            @csrf
            @method('PUT')

            <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                <span>
                    <span class="block text-sm font-semibold text-slate-800 dark:text-slate-200">Bot protection</span>
                    <span class="block text-xs text-slate-500">Detection, blocking and analytics for automated traffic.</span>
                </span>
                <input type="hidden" name="bot_protection_enabled" value="0">
                <input type="checkbox" name="bot_protection_enabled" value="1" class="h-5 w-5 shrink-0"
                       @checked($settings['enabled']) x-model="enabled">
            </label>

            <label class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                <span>
                    <span class="block text-sm font-semibold text-slate-800 dark:text-slate-200">Block automated POST requests</span>
                    <span class="block text-xs text-slate-500">Reject automated clients on login, contact, analytics and SMTP endpoints (403).</span>
                </span>
                <input type="hidden" name="bot_block_automated_posts" value="0">
                <input type="checkbox" name="bot_block_automated_posts" value="1" class="h-5 w-5 shrink-0"
                       @checked($settings['block_automated_posts'])>
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <span class="block text-sm font-semibold text-slate-800 dark:text-slate-200">Automated page rate limit</span>
                    <span class="block text-xs text-slate-500">Verified/known bot GET requests per minute per IP (10–600).</span>
                    <input type="number" name="bot_page_rate_limit" min="10" max="600" required
                           value="{{ $settings['page_rate_limit'] }}" class="input mt-2">
                </label>
                <label class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <span class="block text-sm font-semibold text-slate-800 dark:text-slate-200">Suspicious rate limit</span>
                    <span class="block text-xs text-slate-500">Suspicious automation GET requests per minute per IP (5–120).</span>
                    <input type="number" name="bot_suspicious_rate_limit" min="5" max="120" required
                           value="{{ $settings['suspicious_rate_limit'] }}" class="input mt-2">
                </label>
            </div>

            @if($errors->has('confirm_disable'))
                <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $errors->first('confirm_disable') }}</div>
            @endif

            <label class="flex items-center gap-3 text-sm text-slate-600 dark:text-slate-300" x-show="!enabled" x-cloak>
                <input type="checkbox" name="confirm_disable" value="1" class="h-4 w-4" x-model="confirmDisable">
                I understand that disabling removes automated-traffic detection and blocking for every public endpoint.
            </label>

            <button type="submit" class="btn-accent" @click="submit($event)">Save settings</button>
        </form>
    </div>
@endsection

@push('bottom-scripts')
    <script src="{{ Vite::asset('resources/js/charts.js') }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var series = @json($series);
            var labels = series.map(function (p) { return p.bucket; });
            var detected = series.map(function (p) { return p.detected; });
            var blocked = series.map(function (p) { return p.blocked; });
            var allowed = series.map(function (p) { return p.allowed; });
            var rateLimited = series.map(function (p) { return p.rate_limited; });
            var unknown = series.map(function (p) { return p.unknown; });

            window.STCharts && window.STCharts.line('botTimeline', {
                labels: labels,
                datasets: [
                    { label: 'Detected bot requests', data: detected, color: '#ef4444', fill: true },
                    { label: 'Unknown requests', data: unknown, color: '#64748b', fill: false },
                ],
            });

            window.STCharts && window.STCharts.line('outcomeChart', {
                labels: labels,
                datasets: [
                    { label: 'Blocked', data: blocked, color: '#ef4444', fill: false },
                    { label: 'Allowed bots', data: allowed, color: '#10b981', fill: false },
                    { label: 'Rate-limited', data: rateLimited, color: '#f59e0b', fill: false },
                ],
            });

            window.STCharts && window.STCharts.doughnut('humanChart', {
                labels: ['Human page views', 'Automated (detected)', 'Unknown'],
                data: [@json($humanPageViews), @json($summary['total_bot']), @json($summary['unknown'])],
                colors: ['#4d4de8', '#ef4444', '#64748b'],
            });
        });
    </script>
@endpush
