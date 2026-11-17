@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        @foreach([
            ['Total Visitors', number_format($cards['total_visitors']), 'users', 'from-brand-600/10 to-accent-500/10 text-brand-600'],
            ['Unique (30d)', number_format($cards['unique_visitors_30d']), 'users', 'from-emerald-500/10 to-emerald-500/5 text-emerald-600'],
            ['Page Views (30d)', number_format($cards['page_views_30d']), 'eye', 'from-blue-500/10 to-blue-500/5 text-blue-600'],
            ['Tool Runs (30d)', number_format($cards['tool_usage_30d']), 'tools', 'from-amber-500/10 to-amber-500/5 text-amber-600'],
            ['Successful Ops', number_format($cards['tool_success_30d']), 'check', 'from-emerald-500/10 to-emerald-500/5 text-emerald-600'],
            ['Active Tools', number_format($cards['active_tools']), 'tools', 'from-violet-500/10 to-violet-500/5 text-violet-600'],
            ['Published Posts', number_format($cards['published_posts']), 'blog', 'from-pink-500/10 to-pink-500/5 text-pink-600'],
            ['Scheduled Posts', number_format($cards['scheduled_posts']), 'clock', 'from-orange-500/10 to-orange-500/5 text-orange-600'],
            ['Unread Messages', number_format($cards['unread_messages']), 'doc', 'from-red-500/10 to-red-500/5 text-red-600'],
        ] as [$label, $value, $icon, $tone])
            <div class="card p-4 sm:p-5">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br {{ $tone }}">
                        <x-icon name="{{ $icon }}" class="h-4 w-4"/>
                    </span>
                </div>
                <p class="mt-2 text-2xl font-extrabold tabular-nums text-slate-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <div class="card p-5">
            <h2 class="font-bold text-slate-900">AI Blogging — Today</h2>
            <div class="mt-4 space-y-3 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">Published vs target</span>
                    <span class="font-bold tabular-nums text-slate-900">{{ $automationToday['published_today'] }} / {{ $automationTarget }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                    @php($progress = $automationTarget > 0 ? min(100, (int) round($automationToday['published_today'] / $automationTarget * 100)) : 0)
                    <div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-accent-500" style="width: {{ $progress }}%"></div>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">Completed pipeline runs</span>
                    <span class="font-bold tabular-nums text-slate-900">{{ $automationToday['completed_runs_today'] }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">Failed runs today</span>
                    <span class="font-bold tabular-nums {{ $automationToday['failed_runs_today'] > 0 ? 'text-red-600' : 'text-slate-900' }}">{{ $automationToday['failed_runs_today'] }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">Drafts awaiting review</span>
                    <span class="font-bold tabular-nums text-slate-900">{{ $automationToday['pending_posts'] }}</span>
                </div>
            </div>
            <a href="{{ route('admin.monitoring.index') }}" class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-brand-600">Open AI Monitoring →</a>
        </div>

        <div class="card p-5 xl:col-span-2">
            <h2 class="font-bold text-slate-900">Traffic — last 14 days</h2>
            <div class="mt-4 h-64"><canvas id="trendChart" aria-label="Traffic trend chart" role="img"></canvas></div>
        </div>

        <div class="card p-5">
            <h2 class="font-bold text-slate-900">Top Tools (30 days)</h2>
            @if($topTools->every(fn ($t) => $t->usage_count === 0))
                <p class="mt-6 text-sm text-slate-500">No tool usage recorded yet. Statistics appear here once visitors start using tools.</p>
            @else
                <ul class="mt-4 space-y-3">
                    @foreach($topTools->where('usage_count', '>', 0) as $tool)
                        <li class="flex items-center justify-between gap-3 text-sm">
                            <span class="truncate font-medium text-slate-800">{{ $tool->name }}</span>
                            <span class="badge-slate tabular-nums">{{ number_format($tool->usage_count) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="card mt-6 overflow-hidden">
        <h2 class="border-b border-slate-100 px-5 py-4 font-bold text-slate-900">Recent Tool Activity</h2>
        @if($recentUsage->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-slate-500">No tool activity yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[500px] text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Tool</th>
                            <th class="px-5 py-3 font-semibold">Event</th>
                            <th class="px-5 py-3 font-semibold">Result</th>
                            <th class="px-5 py-3 font-semibold">When</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($recentUsage as $usage)
                            <tr>
                                <td class="px-5 py-3 font-medium text-slate-800">{{ $usage->tool?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $usage->event_type }}</td>
                                <td class="px-5 py-3">
                                    <span class="{{ $usage->success ? 'badge-green' : 'badge-red' }}">{{ $usage->success ? 'Success' : 'Failed' }}</span>
                                </td>
                                <td class="px-5 py-3 text-slate-500">{{ $usage->created_at->diffForHumans() }}</td>
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
            window.STCharts && window.STCharts.line('trendChart', {
                labels: @json($trend->pluck('date')),
                datasets: [
                    { label: 'Page views', data: @json($trend->pluck('views')), color: '#4d4de8', fill: true },
                    { label: 'Tool runs', data: @json($trend->pluck('usage')), color: '#a855f7', fill: false },
                ],
            });
        });
    </script>
@endpush
