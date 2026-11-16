@extends('layouts.admin-auth')

@section('title', 'Admin Sign In')

@section('content')
    <form method="POST" action="{{ route('admin.login.attempt') }}" class="card p-6 sm:p-8">
        @csrf
        <div class="space-y-5">
            <div>
                <label for="email" class="label">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus class="input" placeholder="admin@stunningtouch.com">
            </div>
            <div>
                <label for="password" class="label">Password</label>
                <input id="password" name="password" type="password" required class="input" placeholder="••••••••••">
            </div>
            <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" class="h-4 w-4 rounded accent-brand-600">
                Remember me
            </label>
            <button type="submit" class="btn-accent btn-lg w-full">Sign In</button>
        </div>
    </form>
@endsection
