@extends('layouts.admin')

@section('title', 'Audit Logs')

@section('content')
    <form method="GET" class="mb-4 flex flex-wrap gap-2">
        <input type="search" name="action" value="{{ request('action') }}" placeholder="Filter by action (e.g. tool.created)…" class="input max-w-sm">
        <button type="submit" class="btn-secondary">Filter</button>
    </form>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[800px] text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Time</th>
                        <th class="px-4 py-3 font-semibold">User</th>
                        <th class="px-4 py-3 font-semibold">Action</th>
                        <th class="px-4 py-3 font-semibold">Description</th>
                        <th class="px-4 py-3 font-semibold">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($logs as $log)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-3 whitespace-nowrap text-slate-500">{{ $log->created_at->format('M j, Y H:i') }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ $log->user?->name ?? '—' }}</td>
                            <td class="px-4 py-3"><code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700">{{ $log->action }}</code></td>
                            <td class="px-4 py-3 max-w-[360px] truncate text-slate-600">{{ $log->description }}</td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ $log->ip_address }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No audit entries yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 px-4 py-3">{{ $logs->links() }}</div>
    </div>
@endsection
