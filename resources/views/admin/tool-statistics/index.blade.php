@extends('layouts.admin')

@section('title', 'Tool Statistics')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">Only real processing events are counted — never page loads.</p>
        <div class="flex gap-1 rounded-xl border border-slate-200 bg-white p-1">
            @foreach([7 => '7 Days', 30 => '30 Days', 90 => '90 Days'] as $key => $label)
                <a href="{{ route('admin.tool-statistics.index', ['range' => $key]) }}"
                   class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $range === $key ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        @foreach([
            ['Total Runs', number_format($totals->total ?? 0)],
            ['Successful', number_format($totals->success ?? 0)],
            ['Failed', number_format($totals->failed ?? 0)],
            ['Success Rate', $successRate.'%'],
            ['Unique Users', number_format($totals->unique_users ?? 0)],
        ] as [$label, $value])
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-extrabold tabular-nums text-slate-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="card mt-6 p-5">
        <h2 class="font-bold text-slate-900">Daily tool runs</h2>
        <div class="mt-4 h-72"><canvas id="usageChart" aria-label="Daily usage chart" role="img"></canvas></div>
    </div>

    <div class="card mt-6 overflow-hidden">
        <h2 class="border-b border-slate-100 px-5 py-4 font-bold text-slate-900">Per-tool breakdown</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[700px] text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Tool</th>
                        <th class="px-4 py-3 font-semibold">Total</th>
                        <th class="px-4 py-3 font-semibold">Success</th>
                        <th class="px-4 py-3 font-semibold">Failed</th>
                        <th class="px-4 py-3 font-semibold">Unique Users</th>
                        <th class="px-4 py-3 font-semibold">Success Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($perTool as $tool)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-3 font-semibold text-slate-900">{{ $tool->name }}</td>
                            <td class="px-4 py-3 tabular-nums text-slate-700">{{ number_format($tool->total) }}</td>
                            <td class="px-4 py-3 tabular-nums text-emerald-600">{{ number_format($tool->success) }}</td>
                            <td class="px-4 py-3 tabular-nums text-red-600">{{ number_format($tool->failed) }}</td>
                            <td class="px-4 py-3 tabular-nums text-slate-600">{{ number_format($tool->unique_users) }}</td>
                            <td class="px-4 py-3">
                                @if($tool->total > 0)
                                    <span class="{{ $tool->success / $tool->total >= 0.9 ? 'badge-green' : 'badge-amber' }}">
                                        {{ round(($tool->success / $tool->total) * 100, 1) }}%
                                    </span>
                                @else
                                    <span class="badge-slate">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No usage data in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('bottom-scripts')
    <script src="{{ Vite::asset('resources/js/charts.js') }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            window.STCharts && window.STCharts.bar('usageChart', {
                labels: @json($daily->pluck('date')),
                data: @json($daily->pluck('total')),
            });
        });
    </script>
@endpush
