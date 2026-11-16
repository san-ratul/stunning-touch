<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const KEYS = [
        'site_name' => ['Site Name', 'string', 'Stunning Touch'],
        'site_tagline' => ['Tagline', 'string', 'Your Everyday Tools, All in One Place.'],
        'site_description' => ['Site Description', 'text', ''],
        'contact_email' => ['Contact Email', 'string', ''],
        'twitter_handle' => ['Twitter / X Handle', 'string', ''],
        'footer_note' => ['Footer Note', 'text', ''],
        'google_site_verification' => ['Google Site Verification Code', 'string', ''],
        'analytics_note' => ['Analytics Retention Days', 'string', '90'],
    ];

    public function edit(): View
    {
        return view('admin.settings', [
            'keys' => self::KEYS,
            'values' => collect(self::KEYS)->map(fn ($def, $key) => SiteSetting::get($key, $def[2])),
            'schedulerSecretSet' => filled(config('services.scheduler.secret')),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];

        foreach (self::KEYS as $key => [$label, $type]) {
            $rules[$key] = $type === 'string' ? ['nullable', 'string', 'max:255'] : ['nullable', 'string', 'max:5000'];
        }

        $data = $request->validate($rules);

        foreach ($data as $key => $value) {
            SiteSetting::set($key, $value);
        }

        AuditLog::record('settings.updated', null, 'Updated site settings');

        return redirect()->route('admin.settings.edit')->with('success', 'Settings saved.');
    }
}
