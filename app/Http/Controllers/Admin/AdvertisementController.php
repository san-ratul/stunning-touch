<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdvertisementController extends Controller
{
    public const ENABLED_KEY = 'advertisement_enabled';

    public const SCRIPT_KEY = 'advertisement_script';

    public function edit(): View
    {
        return view('admin.advertisement', [
            'enabled' => SiteSetting::get(self::ENABLED_KEY, '0') === '1',
            'script' => (string) SiteSetting::get(self::SCRIPT_KEY, ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'advertisement_enabled' => ['required', 'in:1,0'],
            'advertisement_script' => ['nullable', 'string', 'max:100000'],
        ]);

        $script = (string) ($validated['advertisement_script'] ?? '');

        SiteSetting::set(self::ENABLED_KEY, $validated['advertisement_enabled']);
        SiteSetting::set(self::SCRIPT_KEY, $script);

        AuditLog::record(
            'advertisement.updated',
            null,
            $validated['advertisement_enabled'] === '1'
                ? 'Advertisement enabled ('.strlen($script).' characters of script saved)'
                : 'Advertisement disabled'
        );

        return redirect()->route('admin.advertisements.edit')->with('success', 'Advertisement settings saved.');
    }
}
