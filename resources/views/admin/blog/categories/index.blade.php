@extends('layouts.admin')

@section('title', 'Blog Categories')

@section('content')
    <div class="mb-4 flex justify-end">
        <a href="{{ route('admin.blog.categories.create') }}" class="btn-accent">+ Add Category</a>
    </div>

    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 font-semibold">Name</th>
                    <th class="px-4 py-3 font-semibold">Slug</th>
                    <th class="px-4 py-3 font-semibold">Posts</th>
                    <th class="px-4 py-3 font-semibold">Status</th>
                    <th class="px-4 py-3 text-right font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($categories as $category)
                    <tr class="hover:bg-slate-50/60">
                        <td class="px-4 py-3 font-semibold text-slate-900">{{ $category->name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $category->slug }}</td>
                        <td class="px-4 py-3"><span class="badge-slate">{{ $category->posts_count }}</span></td>
                        <td class="px-4 py-3"><span class="{{ $category->is_active ? 'badge-green' : 'badge-slate' }}">{{ $category->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <a href="{{ route('admin.blog.categories.edit', $category) }}" class="btn-secondary px-2 py-1 text-xs">Edit</a>
                                <form method="POST" action="{{ route('admin.blog.categories.destroy', $category) }}" onsubmit="return confirm('Delete this blog category?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn-danger px-2 py-1 text-xs">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No blog categories yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
