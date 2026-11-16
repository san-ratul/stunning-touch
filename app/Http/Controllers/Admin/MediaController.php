<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Media;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class MediaController extends Controller
{
    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml',
        'application/pdf', 'text/plain', 'text/csv',
    ];

    public function index(): View
    {
        return view('admin.media.index', [
            'media' => Media::query()->latest()->paginate(24),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:4096', 'mimes:jpg,jpeg,png,webp,gif,svg,pdf,txt,csv'],
            'alt_text' => ['nullable', 'string', 'max:500'],
        ]);

        $file = $request->file('file');

        abort_unless(in_array($file->getMimeType(), self::ALLOWED_MIMES, true), 422, 'File type not allowed.');

        $path = $file->store('media', 'public');

        $media = Media::create([
            'user_id' => auth()->id(),
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'disk' => 'public',
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'alt_text' => $request->input('alt_text'),
        ]);

        AuditLog::record('media.uploaded', $media, 'Uploaded file: '.$media->file_name);

        return redirect()->route('admin.media.index')->with('success', 'File uploaded.');
    }

    public function destroy(Media $media): RedirectResponse
    {
        AuditLog::record('media.deleted', $media, 'Deleted file: '.$media->file_name);

        Storage::disk($media->disk)->delete($media->file_path);
        $media->delete();

        return redirect()->route('admin.media.index')->with('success', 'File deleted.');
    }
}
