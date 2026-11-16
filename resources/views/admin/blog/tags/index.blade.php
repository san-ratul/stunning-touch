@extends('layouts.admin')

@section('title', 'Blog Tags')

@section('content')
    <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Tag</th>
                        <th class="px-4 py-3 font-semibold">Slug</th>
                        <th class="px-4 py-3 font-semibold">Posts</th>
                        <th class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($tags as $tag)
                        <tr class="hover:bg-slate-50/60" x-data="{ editing: false }">
                            <td class="px-4 py-3" x-show="!editing">
                                <span class="font-semibold text-slate-900">{{ $tag->name }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-600" x-show="!editing">{{ $tag->slug }}</td>
                            <td class="px-4 py-3" x-show="!editing"><span class="badge-slate">{{ $tag->posts_count }}</span></td>
                            <td class="px-4 py-3" x-show="!editing">
                                <div class="flex justify-end gap-1.5">
                                    <button type="button" class="btn-secondary px-2 py-1 text-xs" @click="editing = true">Rename</button>
                                    <form method="POST" action="{{ route('admin.blog.tags.destroy', $tag) }}" onsubmit="return confirm('Delete this tag?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn-danger px-2 py-1 text-xs">Delete</button>
                                    </form>
                                </div>
                            </td>
                            <td colspan="4" x-show="editing" x-cloak>
                                <form method="POST" action="{{ route('admin.blog.tags.update', $tag) }}" class="flex flex-wrap items-center gap-2 py-1">
                                    @csrf @method('PUT')
                                    <input name="name" value="{{ $tag->name }}" class="input max-w-[200px]" required>
                                    <input name="slug" value="{{ $tag->slug }}" class="input max-w-[200px]" pattern="[a-z0-9]+(-[a-z0-9]+)*" required>
                                    <button type="submit" class="btn-primary px-3 py-1.5 text-xs">Save</button>
                                    <button type="button" class="btn-ghost px-3 py-1.5 text-xs" @click="editing = false">Cancel</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-slate-500">No tags yet. Tags can also be created when editing posts via AI output.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('admin.blog.tags.store') }}" class="card h-fit p-6">
            @csrf
            <h2 class="mb-4 font-bold text-slate-900">Add Tag</h2>
            <div class="space-y-4">
                <div>
                    <label class="label" for="tag-name">Name *</label>
                    <input id="tag-name" name="name" required class="input" maxlength="100">
                </div>
                <div>
                    <label class="label" for="tag-slug">Slug (optional — generated from name)</label>
                    <input id="tag-slug" name="slug" class="input" pattern="[a-z0-9]+(-[a-z0-9]+)*">
                </div>
                <button type="submit" class="btn-accent w-full">Create Tag</button>
            </div>
        </form>
    </div>
@endsection
