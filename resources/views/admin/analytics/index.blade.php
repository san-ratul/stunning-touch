@extends('layouts.admin')

@section('title', 'Visitor Analytics')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">Privacy-conscious analytics: hashed visitor IDs, no raw IPs stored.</p>
        <div class="flex gap-1 rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-slate-800">
            @foreach(['today' => 'Today', '7days' => '7 Days', '30days' => '30 Days', '90days' => '90 Days', '365days' => '1 Year'] as $key => $label)
                <a href="{{ route('admin.analytics.index', ['range' => $key]) }}"
                   class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $range === $key ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        @foreach([
            ['Total Visitors', number_format($stats['total_visitors'])],
            ['Unique Visitors', number_format($stats['unique_visitors'])],
            ['Page Views', number_format($stats['page_views'])],
            ['Returning', number_format($stats['returning_visitors'])],
            ['Tool Runs', number_format(\App\Models\ToolUsage::query()->where('created_at', '>=', now()->subDays(\App\Support\Analytics::daysFor($range))->startOfDay())->count())],
        ] as [$label, $value])
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-extrabold tabular-nums text-slate-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="card mt-6 p-5">
        <h2 class="font-bold text-slate-900">Daily traffic</h2>
        <div class="mt-4 h-72"><canvas id="dailyChart" aria-label="Daily traffic chart" role="img"></canvas></div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="card p-5">
            <h2 class="font-bold text-slate-900">Devices</h2>
            <div class="mx-auto mt-4 h-64"><canvas id="deviceChart" aria-label="Device distribution chart" role="img"></canvas></div>
        </div>
        <div class="card p-5">
            <h2 class="font-bold text-slate-900">Browsers</h2>
            <div class="mx-auto mt-4 h-64"><canvas id="browserChart" aria-label="Browser distribution chart" role="img"></canvas></div>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="card p-5">
            <h2 class="font-bold text-slate-900">Traffic Sources</h2>
            @if(collect($sourceCounts)->sum() === 0)
                <p class="mt-8 text-sm text-slate-500">No traffic recorded yet.</p>
            @else
                <div class="mx-auto mt-4 h-56"><canvas id="sourceChart" aria-label="Traffic sources chart" role="img"></canvas></div>
                <ul class="mt-5 grid grid-cols-2 gap-2 text-sm">
                    @foreach(['direct' => 'Direct', 'search' => 'Search engines', 'social' => 'Social media', 'referral' => 'Referrals'] as $key => $label)
                        <li class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                            <span class="text-slate-600">{{ $label }}</span>
                            <span class="badge-slate tabular-nums">{{ number_format($sourceCounts[$key] ?? 0) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        <div class="card p-5">
            <h2 class="font-bold text-slate-900">Countries</h2>
            @if($countries->isEmpty())
                <p class="mt-8 text-sm text-slate-500">
                    No country data yet. Countries come from edge geo headers (e.g. Cloudflare
                    CF-IPCountry) or an approximate browser-locale hint.
                </p>
            @else
                <div class="mt-4 h-56"><canvas id="countryChart" aria-label="Visitors by country chart" role="img"></canvas></div>
                <ul class="mt-5 max-h-40 space-y-1.5 overflow-y-auto text-sm scroll-thin">
                    @foreach($countries->take(12) as $row)
                        <li class="flex items-center justify-between gap-3 rounded-lg px-2 py-1 hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <span class="flex items-center gap-2 text-slate-700">
                                <span class="badge-slate">{{ $row->country }}</span>
                                {{ \App\Support\CountryNames::name($row->country) ?? '—' }}
                            </span>
                            <span class="text-xs tabular-nums text-slate-500">{{ number_format($row->views) }} views · {{ number_format($row->visitors) }} visitors</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @if($countries->isNotEmpty())
        <div class="card mt-6 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-bold text-slate-900">Visitors world map</h2>
                <p class="text-xs text-slate-500">Hover a country for details · darker = more visitors · approximate</p>
            </div>
            <div class="mt-4 overflow-hidden rounded-xl bg-slate-50 p-2 dark:bg-slate-800/40">
                <div id="countryMap" class="relative h-[420px]" role="img" aria-label="World map of visitors by country"></div>
            </div>
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        @foreach([['Top Pages', $topPages, 'page_url', 'views'], ['Landing Pages', $landingPages, 'page_url', 'entries'], ['Referrers', $referrers, 'source', 'views']] as [$heading, $rows, $labelKey, $valueKey])
            <div class="card overflow-hidden">
                <h2 class="border-b border-slate-100 px-4 py-3 font-bold text-slate-900">{{ $heading }}</h2>
                @if($rows->isEmpty())
                    <p class="px-4 py-8 text-center text-sm text-slate-500">No data yet.</p>
                @else
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach($rows as $row)
                            <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <span class="truncate text-slate-700">{{ $row->{$labelKey} ?? '(direct)' }}</span>
                                <span class="badge-slate shrink-0 tabular-nums">{{ number_format($row->{$valueKey}) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>

    <div class="card mt-6 overflow-hidden">
        <h2 class="border-b border-slate-100 px-4 py-3 font-bold text-slate-900">Countries</h2>
        @if($countries->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-slate-500">
                Country data requires an edge header (e.g. Cloudflare CF-IPCountry). No country data recorded yet.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[400px] text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="px-4 py-3 font-semibold">Country</th><th class="px-4 py-3 font-semibold">Views</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($countries as $row)
                            <tr>
                                <td class="px-4 py-2.5 font-medium text-slate-700">{{ $row->country }}</td>
                                <td class="px-4 py-2.5 tabular-nums text-slate-600">{{ number_format($row->views) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection

@push('bottom-scripts')
    <script src="{{ Vite::asset('resources/js/charts.js') }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            window.STCharts && window.STCharts.line('dailyChart', {
                labels: @json($daily->pluck('date')),
                datasets: [
                    { label: 'Page views', data: @json($daily->pluck('views')), color: '#4d4de8', fill: true },
                    { label: 'Unique visitors', data: @json($daily->pluck('unique')), color: '#10b981', fill: false },
                    { label: 'Tool runs', data: @json($daily->pluck('usage')), color: '#a855f7', fill: false },
                ],
            });
            window.STCharts && window.STCharts.doughnut('deviceChart', {
                labels: @json($devices->pluck('device')),
                data: @json($devices->pluck('views')),
                colors: ['#4d4de8', '#a855f7', '#10b981', '#f59e0b', '#64748b'],
            });
            window.STCharts && window.STCharts.doughnut('browserChart', {
                labels: @json($browsers->pluck('browser')),
                data: @json($browsers->pluck('views')),
                colors: ['#4d4de8', '#a855f7', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#8b5cf6', '#64748b'],
            });

            @php
                $presentSources = array_keys(array_filter($sourceCounts));
                $sourceLabels = collect(['direct' => 'Direct', 'search' => 'Search', 'social' => 'Social', 'referral' => 'Referrals'])
                    ->only($presentSources)
                    ->values()
                    ->all();
                $sourceData = collect($sourceCounts)->filter()->values()->all();
                $countryLabels = $countries->take(10)->map(fn ($r) => \App\Support\CountryNames::name($r->country) ?? $r->country)->all();
                $countryData = $countries->take(10)->pluck('views')->all();
                $mapData = $countries->map(fn ($r) => [
                    'code' => $r->country,
                    'name' => \App\Support\CountryNames::name($r->country) ?? $r->country,
                    'views' => (int) $r->views,
                    'visitors' => (int) $r->visitors,
                ])->all();
            @endphp

            @if($sourceData !== [])
                window.STCharts && window.STCharts.doughnut('sourceChart', {
                    labels: @json($sourceLabels),
                    data: @json($sourceData),
                    colors: ['#64748b', '#4d4de8', '#a855f7', '#10b981'],
                });
            @endif

            @if($countries->isNotEmpty())
                window.STCharts && window.STCharts.bar('countryChart', {
                    labels: @json($countryLabels),
                    data: @json($countryData),
                    horizontal: true,
                });
                window.STCharts && window.STCharts.map('countryMap', {
                    data: @json($mapData),
                });
            @endif
        });
    </script>
@endpush
