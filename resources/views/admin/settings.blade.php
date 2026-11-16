@extends('layouts.admin')

@section('title', 'Site Settings')

@section('content')
    <form method="POST" action="{{ route('admin.settings.update') }}" class="max-w-3xl space-y-6">
        @csrf
        @method('PUT')

        <div class="card p-6">
            <h2 class="mb-4 font-bold text-slate-900">General</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach($keys as $key => [$label, $type, $default])
                    <div class="{{ $type === 'text' ? 'sm:col-span-2' : '' }}">
                        <label class="label" for="{{ $key }}">{{ $label }}</label>
                        @if($type === 'text')
                            <textarea id="{{ $key }}" name="{{ $key }}" rows="3" class="input">{{ old($key, $values[$key]) }}</textarea>
                        @else
                            <input id="{{ $key }}" name="{{ $key }}" value="{{ old($key, $values[$key]) }}" class="input" maxlength="255">
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card p-6">
            <h2 class="mb-2 font-bold text-slate-900">Cron / Scheduler</h2>
            <p class="text-sm leading-relaxed text-slate-600">
                The scheduler secret comes from the <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">SCHEDULER_SECRET</code> env variable.
                @if($schedulerSecretSet)
                    <span class="badge-green ml-1">Configured</span>
                @else
                    <span class="badge-amber ml-1">Not set — add SCHEDULER_SECRET to .env to use the web cron endpoint</span>
                @endif
                See <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">.docs/CRON_SETUP.md</code> for exact cPanel instructions.
            </p>
        </div>

        <button type="submit" class="btn-accent">Save Settings</button>
    </form>
@endsection
