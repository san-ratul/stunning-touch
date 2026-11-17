<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Support\BotAnalytics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BotProtectionController extends Controller
{
    public function index(Request $request): View
    {
        $range = $request->query('range', '24h');
        $range = in_array($range, array_keys(BotAnalytics::RANGES), true) ? $range : '24h';

        return view('admin.bot-protection.index', [
            'range' => $range,
            'summary' => BotAnalytics::summary($range),
            'series' => BotAnalytics::series($range),
            'categories' => BotAnalytics::categories($range),
            'topEndpoints' => BotAnalytics::topEndpoints($range),
            'recentEvents' => BotAnalytics::recentEvents($range),
            'humanPageViews' => BotAnalytics::humanPageViews($range),
            'settings' => [
                'enabled' => SiteSetting::get('bot_protection_enabled', '1') === '1',
                'block_automated_posts' => SiteSetting::get('bot_block_automated_posts', '1') === '1',
                'page_rate_limit' => (int) SiteSetting::get('bot_page_rate_limit', (string) config('bot.page_rate_limit', 60)),
                'suspicious_rate_limit' => (int) SiteSetting::get('bot_suspicious_rate_limit', (string) config('bot.suspicious_rate_limit', 30)),
            ],
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'bot_protection_enabled' => ['required', Rule::in(['0', '1'])],
            'bot_block_automated_posts' => ['required', Rule::in(['0', '1'])],
            'bot_page_rate_limit' => ['required', 'integer', 'between:10,600'],
            'bot_suspicious_rate_limit' => ['required', 'integer', 'between:5,120'],
            'confirm_disable' => ['nullable', Rule::in(['1'])],
        ]);

        if ($validated['bot_protection_enabled'] === '0' && ($validated['confirm_disable'] ?? null) !== '1') {
            return back()->withErrors([
                'confirm_disable' => 'Disabling bot protection requires an explicit confirmation. It removes automated-traffic detection and blocking for every public endpoint.',
            ])->withInput();
        }

        foreach (['bot_protection_enabled', 'bot_block_automated_posts', 'bot_page_rate_limit', 'bot_suspicious_rate_limit'] as $key) {
            SiteSetting::set($key, (string) $validated[$key]);
        }

        AuditLog::record('bot_protection.settings_updated', null, 'Bot protection settings updated', [
            'enabled' => $validated['bot_protection_enabled'] === '1',
            'block_automated_posts' => $validated['bot_block_automated_posts'] === '1',
            'page_rate_limit' => $validated['bot_page_rate_limit'],
            'suspicious_rate_limit' => $validated['bot_suspicious_rate_limit'],
        ]);

        return back()->with('success', 'Bot protection settings updated.');
    }
}
