@extends('layouts.admin')

@section('title', 'Contact Messages')

@section('content')
    <div class="card overflow-hidden">
        @forelse($messages as $message)
            <div class="border-b border-slate-100 p-5 {{ $message->is_read ? '' : 'bg-brand-50/40' }}" x-data="{ open: {{ $message->is_read ? 'false' : 'true' }} }">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-slate-900">{{ $message->subject }}</p>
                        <p class="text-sm text-slate-500">{{ $message->name }} &lt;{{ $message->email }}&gt; · {{ $message->created_at->format('M j, Y H:i') }}</p>
                    </div>
                    <div class="flex gap-1.5">
                        <form method="POST" action="{{ route('admin.contact-messages.toggle-read', $message) }}">
                            @csrf
                            <button type="submit" class="btn-secondary px-2 py-1 text-xs">{{ $message->is_read ? 'Mark unread' : 'Mark read' }}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.contact-messages.destroy', $message) }}" onsubmit="return confirm('Delete this message?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn-danger px-2 py-1 text-xs">Delete</button>
                        </form>
                    </div>
                </div>
                <button type="button" class="mt-2 text-xs font-medium text-brand-600" @click="open = !open" x-text="open ? 'Hide message' : 'Show message'"></button>
                <p x-show="open" class="mt-2 whitespace-pre-wrap rounded-xl bg-white p-4 text-sm leading-relaxed text-slate-700 ring-1 ring-slate-100">{{ $message->message }}</p>
            </div>
        @empty
            <p class="px-4 py-12 text-center text-slate-500">No contact messages yet.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $messages->links() }}</div>
@endsection
