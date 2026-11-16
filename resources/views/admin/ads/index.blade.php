@extends('layouts.admin')

@section('title', 'Advertisements')

@section('content')
    <div class="mb-4 flex justify-end">
        <a href="{{ route('admin.advertisements.create') }}" class="btn-accent">+ Add Advertisement</a>
    </div>

    <div class="card overflow-hidden mb-8">
        <h2 class="border-b border-slate-100 px-5 py-4 font-bold text-slate-900">Ad Placements</h2>
        <div class="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($placements as $placement)
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-slate-900">{{ $placement->name }}</p>
                        <span class="{{ $placement->is_active ? 'badge-green' : 'badge-slate' }}">{{ $placement->is_active ? 'On' : 'Off' }}</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500"><code>{{ $placement->key }}</code> · {{ $placement->advertisements_count }} ad(s)</p>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card overflow-hidden">
        <h2 class="border-b border-slate-100 px-5 py-4 font-bold text-slate-900">All Advertisements</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Name</th>
                        <th class="px-4 py-3 font-semibold">Placement</th>
                        <th class="px-4 py-3 font-semibold">Provider</th>
                        <th class="px-4 py-3 font-semibold">Type</th>
                        <th class="px-4 py-3 font-semibold">Schedule</th>
                        <th class="px-4 py-3 font-semibold">Stats</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($ads as $ad)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-3 font-semibold text-slate-900">{{ $ad->name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $ad->placement?->name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $ad->provider }}</td>
                            <td class="px-4 py-3"><span class="badge-slate">{{ ucfirst($ad->ad_type) }}</span></td>
                            <td class="px-4 py-3 text-xs text-slate-500">
                                @if($ad->starts_at || $ad->ends_at)
                                    {{ $ad->starts_at?->format('M j, Y') ?? '∞' }} → {{ $ad->ends_at?->format('M j, Y') ?? '∞' }}
                                @else
                                    Always
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs tabular-nums text-slate-500">
                                {{ number_format($ad->impressions) }} imp · {{ number_format($ad->clicks) }} clicks
                            </td>
                            <td class="px-4 py-3">
                                <span class="{{ $ad->is_active ? 'badge-green' : 'badge-slate' }}">{{ $ad->is_active ? 'Active' : 'Disabled' }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <a href="{{ route('admin.advertisements.preview', $ad) }}" target="_blank" class="btn-ghost px-2 py-1 text-xs">Preview</a>
                                    <a href="{{ route('admin.advertisements.edit', $ad) }}" class="btn-secondary px-2 py-1 text-xs">Edit</a>
                                    <form method="POST" action="{{ route('admin.advertisements.toggle', $ad) }}">
                                        @csrf
                                        <button type="submit" class="btn-ghost px-2 py-1 text-xs">{{ $ad->is_active ? 'Disable' : 'Enable' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.advertisements.destroy', $ad) }}" onsubmit="return confirm('Delete this advertisement?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn-danger px-2 py-1 text-xs">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-slate-500">No advertisements yet. Create one to monetize placements.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 px-4 py-3">{{ $ads->links() }}</div>
    </div>
@endsection
