@extends('layouts.admin')

@section('title', 'Categories')

@section('content')
    <div class="mb-4 flex justify-end">
        <a href="{{ route('admin.categories.create') }}" class="btn-accent">+ Add Category</a>
    </div>

    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 font-semibold">Category</th>
                    <th class="px-4 py-3 font-semibold">Slug</th>
                    <th class="px-4 py-3 font-semibold">Tools</th>
                    <th class="px-4 py-3 font-semibold">Order</th>
                    <th class="px-4 py-3 font-semibold">Status</th>
                    <th class="px-4 py-3 text-right font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($categories as $category)
                    <tr class="hover:bg-slate-50/60">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600/10 text-brand-600">
                                    <x-icon name="{{ $category->icon ?: 'tools' }}" class="h-4 w-4"/>
                                </span>
                                <span class="font-semibold text-slate-900">{{ $category->name }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-slate-600">/{{ $category->slug }}</td>
                        <td class="px-4 py-3"><span class="badge-slate">{{ $category->tools_count }}</span></td>
                        <td class="px-4 py-3 text-slate-600">{{ $category->display_order }}</td>
                        <td class="px-4 py-3"><span class="{{ $category->is_active ? 'badge-green' : 'badge-slate' }}">{{ $category->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <a href="/{{ $category->slug }}" target="_blank" class="btn-ghost px-2 py-1 text-xs">View</a>
                                <a href="{{ route('admin.categories.edit', $category) }}" class="btn-secondary px-2 py-1 text-xs">Edit</a>
                                <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" onsubmit="return confirm('Delete this category?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn-danger px-2 py-1 text-xs">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No categories yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
