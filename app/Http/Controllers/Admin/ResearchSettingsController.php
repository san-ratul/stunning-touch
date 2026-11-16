<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ResearchSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ResearchSettingsController extends Controller
{
    public function edit(): View
    {
        return view('admin.ai.research', [
            'settings' => ResearchSetting::current(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'provider' => ['required', 'in:none,tavily,serpapi'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'rss_feeds' => ['nullable', 'string', 'max:5000'],
            'trusted_domains' => ['nullable', 'string', 'max:2000'],
            'sources_per_source' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $data['enabled'] = (bool) ($data['enabled'] ?? false);

        $data['rss_feeds'] = collect(preg_split('/\r\n|\r|\n/', (string) ($data['rss_feeds'] ?? '')))
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => filled($line) && filter_var($line, FILTER_VALIDATE_URL))
            ->unique()
            ->values()
            ->all();

        $data['trusted_domains'] = collect(preg_split('/[\r\n,]+/', (string) ($data['trusted_domains'] ?? '')))
            ->map(fn ($item) => strtolower(trim(preg_replace('/^https?:\/\/(www\.)?/', '', trim($item)) ?? '', ' /.')))
            ->filter(fn ($item) => filled($item) && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $item))
            ->unique()
            ->values()
            ->all();

        $settings = ResearchSetting::current();

        // Only overwrite the stored key when a new one was submitted.
        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }

        $settings->update($data);

        AuditLog::record('research_settings.updated', null, 'Updated research settings');

        return redirect()->route('admin.research.edit')->with('success', 'Research settings saved. The API key is stored encrypted.');
    }
}
