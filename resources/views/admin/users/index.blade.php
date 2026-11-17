@extends('layouts.admin')

@section('title', 'Admin Users')

@section('content')
    <div class="mb-4 flex justify-end">
        <a href="{{ route('admin.users.create') }}" class="btn-accent">+ Add Admin User</a>
    </div>

    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3 font-semibold">User</th>
                    <th class="px-4 py-3 font-semibold">Last Login</th>
                    <th class="px-4 py-3 font-semibold">Audit Entries</th>
                    <th class="px-4 py-3 font-semibold">Status</th>
                    <th class="px-4 py-3 text-right font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($users as $user)
                    <tr class="hover:bg-slate-50/60">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-600/10 text-sm font-bold text-brand-700">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                                <div>
                                    <p class="font-semibold text-slate-900">{{ $user->name }}</p>
                                    <p class="text-xs text-slate-500">{{ $user->email }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                        <td class="px-4 py-3"><span class="badge-slate">{{ $user->audit_logs_count }}</span></td>
                        <td class="px-4 py-3"><span class="{{ $user->is_active ? 'badge-green' : 'badge-red' }}">{{ $user->is_active ? 'Active' : 'Disabled' }}</span> @if($user->artisan_runner_access)<span class="badge-slate" title="May run approved Artisan commands">artisan</span>@endif</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <a href="{{ route('admin.users.edit', $user) }}" class="btn-secondary px-2 py-1 text-xs">Edit</a>
                                @if($user->id !== auth()->id())
                                    <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('Delete this admin user?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn-danger px-2 py-1 text-xs">Delete</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
