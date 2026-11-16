@extends('layouts.admin')

@section('title', 'Static Pages')

@section('content')
    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 font-semibold">Page</th>
                    <th class="px-4 py-3 font-semibold">URL</th>
                    <th class="px-4 py-3 font-semibold">Status</th>
                    <th class="px-4 py-3 text-right font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($pages as $page)
                    <tr class="hover:bg-slate-50/60">
                        <td class="px-4 py-3 font-semibold text-slate-900">{{ $page->title }}</td>
                        <td class="px-4 py-3 text-slate-600">/{{ $page->slug }}</td>
                        <td class="px-4 py-3"><span class="{{ $page->is_active ? 'badge-green' : 'badge-slate' }}">{{ $page->is_active ? 'Live' : 'Hidden' }}</span></td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <a href="/{{ $page->slug }}" target="_blank" class="btn-ghost px-2 py-1 text-xs">View</a>
                                <a href="{{ route('admin.pages.edit', $page) }}" class="btn-secondary px-2 py-1 text-xs">Edit</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
