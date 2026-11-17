@extends('layouts.admin')

@section('title', $user->exists ? 'Edit Admin User' : 'Create Admin User')

@section('content')
    <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="max-w-xl space-y-6">
        @csrf
        @if($user->exists) @method('PUT') @endif

        <div class="card space-y-4 p-6">
            <div>
                <label class="label" for="name">Name *</label>
                <input id="name" name="name" value="{{ old('name', $user->name) }}" required class="input" maxlength="100">
            </div>
            <div>
                <label class="label" for="email">Email *</label>
                <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required class="input" maxlength="150">
            </div>
            <div>
                <label class="label" for="password">Password {{ $user->exists ? '(leave blank to keep current)' : '*' }}</label>
                <input id="password" name="password" type="password" {{ $user->exists ? '' : 'required' }} class="input" minlength="10" autocomplete="new-password">
                <p class="mt-1 text-xs text-slate-500">Minimum 10 characters with letters and numbers.</p>
            </div>
            <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-700">
                <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded accent-brand-600" {{ old('is_active', $user->exists ? $user->is_active : true) ? 'checked' : '' }}>
                Active (can sign in)
            </label>

            {{-- Dedicated Artisan Runner permission. Never shown for your
                 own account: administrators cannot grant it to themselves. --}}
            @if(! $user->exists || $user->id !== auth()->id())
                <label class="flex cursor-pointer items-start gap-2.5 text-sm text-slate-700">
                    <input type="checkbox" name="artisan_runner_access" value="1" class="mt-0.5 h-4 w-4 rounded accent-brand-600" {{ old('artisan_runner_access', $user->artisan_runner_access ?? false) ? 'checked' : '' }}>
                    <span>
                        Artisan Command Runner access
                        <span class="block text-xs font-normal text-slate-500">Allows running approved Artisan commands (e.g. db:seed) without SSH. Grant sparingly.</span>
                    </span>
                </label>
            @elseif($user->artisan_runner_access)
                <p class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-xs text-slate-500">
                    This is your own account — Artisan Runner access ({{ auth()->user()->artisan_runner_access ? 'granted to you' : 'not granted' }}) can only be changed by another administrator.
                </p>
            @endif
        </div>

        <div class="flex gap-3">
            <button type="submit" class="btn-accent">{{ $user->exists ? 'Save Changes' : 'Create User' }}</button>
            <a href="{{ route('admin.users.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
@endsection
