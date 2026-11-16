@extends('layouts.admin')

@section('title', 'Tools Management')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex flex-1 flex-wrap gap-2">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search tools…" class="input max-w-xs">
            <select name="category" class="input max-w-[200px]">
                <option value="">All categories</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" {{ request('category') == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-secondary">Filter</button>
        </form>
        <a href="{{ route('admin.tools.create') }}" class="btn-accent">+ Add Tool</a>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Tool</th>
                        <th class="px-4 py-3 font-semibold">Category</th>
                        <th class="px-4 py-3 font-semibold">Component Key</th>
                        <th class="px-4 py-3 font-semibold">Order</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($tools as $tool)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600/10 text-brand-600">
                                        <x-icon name="{{ $tool->icon ?: 'tools' }}" class="h-4 w-4"/>
                                    </span>
                                    <div>
                                        <p class="font-semibold text-slate-900">{{ $tool->name }}</p>
                                        <p class="text-xs text-slate-500">/{{ $tool->slug }} {{ $tool->is_featured ? '· ★ Featured' : '' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $tool->category?->name }}</td>
                            <td class="px-4 py-3"><code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700">{{ $tool->component_key }}</code></td>
                            <td class="px-4 py-3 text-slate-600">{{ $tool->display_order }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <span class="{{ $tool->is_active ? 'badge-green' : 'badge-slate' }}">{{ $tool->is_active ? 'Active' : 'Inactive' }}</span>
                                    @if($tool->is_archived)<span class="badge-amber">Archived</span>@endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <a href="{{ url('/'.$tool->slug) }}" target="_blank" class="btn-ghost px-2 py-1 text-xs">View</a>
                                    <a href="{{ route('admin.tools.edit', $tool) }}" class="btn-secondary px-2 py-1 text-xs">Edit</a>
                                    <form method="POST" action="{{ route('admin.tools.toggle-active', $tool) }}">
                                        @csrf
                                        <button type="submit" class="btn-ghost px-2 py-1 text-xs">{{ $tool->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.tools.archive', $tool) }}">
                                        @csrf
                                        <button type="submit" class="btn-ghost px-2 py-1 text-xs">{{ $tool->is_archived ? 'Unarchive' : 'Archive' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.tools.destroy', $tool) }}" onsubmit="return confirm('Delete this tool permanently?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn-danger px-2 py-1 text-xs">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No tools found. Create your first tool.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 px-4 py-3">{{ $tools->links() }}</div>
    </div>
@endsection
