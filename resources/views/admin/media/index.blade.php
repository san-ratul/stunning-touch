@extends('layouts.admin')

@section('title', 'Media Library')

@section('content')
    <form method="POST" action="{{ route('admin.media.store') }}" enctype="multipart/form-data" class="card mb-6 p-6">
        @csrf
        <h2 class="mb-4 font-bold text-slate-900">Upload Media</h2>
        <div class="grid items-end gap-4 sm:grid-cols-[2fr_2fr_auto]">
            <div>
                <label class="label" for="file">File (max 4 MB)</label>
                <input id="file" type="file" name="file" required accept=".jpg,.jpeg,.png,.webp,.gif,.svg,.pdf,.txt,.csv" class="input file:mr-3 file:rounded-lg file:border-0 file:bg-brand-600 file:px-3 file:py-1.5 file:text-white">
            </div>
            <div>
                <label class="label" for="alt">Alt text (for images)</label>
                <input id="alt" name="alt_text" class="input" maxlength="500">
            </div>
            <button type="submit" class="btn-accent">Upload</button>
        </div>
        <p class="mt-2 text-xs text-slate-500">Allowed: JPG, PNG, WebP, GIF, SVG, PDF, TXT, CSV. Files are stored in the public storage disk.</p>
    </form>

    @if($media->isEmpty())
        <div class="card p-12 text-center text-slate-500">No media uploaded yet.</div>
    @else
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
            @foreach($media as $item)
                <div class="card overflow-hidden" x-data="{ confirmDelete: false }">
                    <div class="flex h-28 items-center justify-center border-b border-slate-100 bg-slate-50">
                        @if(str_starts_with((string) $item->mime_type, 'image/'))
                            <img src="{{ $item->url() }}" alt="{{ $item->alt_text ?: $item->file_name }}" class="h-full w-full object-cover" loading="lazy">
                        @else
                            <x-icon name="doc" class="h-8 w-8 text-slate-400"/>
                        @endif
                    </div>
                    <div class="p-3">
                        <p class="truncate text-xs font-semibold text-slate-900" title="{{ $item->file_name }}">{{ $item->file_name }}</p>
                        <p class="mt-0.5 text-[11px] text-slate-500">{{ number_format($item->size / 1024, 0) }} KB · {{ $item->created_at->format('M j') }}</p>
                        <div class="mt-2 flex gap-1.5">
                            <button type="button" class="btn-secondary flex-1 px-2 py-1 text-[11px]"
                                    onclick="navigator.clipboard.writeText('{{ $item->url() }}'); this.textContent = 'Copied!'">Copy URL</button>
                            <form method="POST" action="{{ route('admin.media.destroy', $item) }}" onsubmit="return confirm('Delete this file?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn-danger px-2 py-1 text-[11px]">✕</button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-6">{{ $media->links() }}</div>
    @endif
@endsection
