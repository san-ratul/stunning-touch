<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StaticPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function index(): View
    {
        return view('admin.pages.index', [
            'pages' => StaticPage::query()->orderBy('title')->get(),
        ]);
    }

    public function edit(StaticPage $page): View
    {
        return view('admin.pages.form', ['page' => $page]);
    }

    public function update(Request $request, StaticPage $page): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'content' => ['nullable', 'string', 'max:200000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);

        $page->update($validated);
        AuditLog::record('page.updated', $page, 'Updated static page: '.$page->slug);

        return redirect()->route('admin.pages.index')->with('success', 'Page updated.');
    }
}
