@extends('layouts.admin')

@section('title', 'Change Password')

@section('content')
    <div class="mx-auto max-w-xl">
        <div class="card p-6">
            <h2 class="mb-1 font-bold text-slate-900">Change Password</h2>
            <p class="mb-5 text-sm text-slate-600">
                Update the password for your own admin account ({{ auth()->user()?->email }}).
                The stored password is never displayed. After a successful change, all other signed-in sessions are signed out.
            </p>

            <form method="POST" action="{{ route('admin.account.password.update') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label class="label" for="current_password">Current password *</label>
                    <div x-data="{ show: false }" class="relative">
                        <input id="current_password" name="current_password" :type="show ? 'text' : 'password'" required class="input pr-10" autocomplete="current-password">
                        <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 btn-ghost px-2 py-1" @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'">
                            <x-icon name="eye" class="h-4 w-4" x-show="!show"/>
                            <x-icon name="eye-slash" class="h-4 w-4" x-show="show" x-cloak/>
                        </button>
                    </div>
                    @error('current_password')
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="label" for="password">New password *</label>
                    <div x-data="{ show: false }" class="relative">
                        <input id="password" name="password" :type="show ? 'text' : 'password'" required class="input pr-10" minlength="10" autocomplete="new-password">
                        <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 btn-ghost px-2 py-1" @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'">
                            <x-icon name="eye" class="h-4 w-4" x-show="!show"/>
                            <x-icon name="eye-slash" class="h-4 w-4" x-show="show" x-cloak/>
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">At least 10 characters, including at least one letter and one digit.</p>
                    @error('password')
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="label" for="password_confirmation">Confirm new password *</label>
                    <div x-data="{ show: false }" class="relative">
                        <input id="password_confirmation" name="password_confirmation" :type="show ? 'text' : 'password'" required class="input pr-10" autocomplete="new-password">
                        <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 btn-ghost px-2 py-1" @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'">
                            <x-icon name="eye" class="h-4 w-4" x-show="!show"/>
                            <x-icon name="eye-slash" class="h-4 w-4" x-show="show" x-cloak/>
                        </button>
                    </div>
                    @error('password_confirmation')
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="pt-2">
                    <button type="submit" class="btn-accent">Change Password</button>
                    <a href="{{ route('admin.dashboard') }}" class="btn-ghost ml-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
