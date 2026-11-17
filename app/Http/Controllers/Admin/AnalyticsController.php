<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PageView;
use App\Models\ToolUsage;
use App\Support\Analytics;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function index(): View
    {
        $days = Analytics::daysFor(request()->query('range', '30days'));
        $since = now()->subDays($days)->startOfDay();

        $daily = collect(range($days - 1, 0))->map(function ($daysAgo) use ($days) {
            $step = max(1, (int) ceil($days / 30));
            if ($step > 1 && $daysAgo % $step !== 0) {
                return null;
            }
            $day = now()->subDays($daysAgo)->toDateString();

            return [
                'date' => $day,
                'views' => PageView::query()->whereDate('visited_at', $day)->count(),
                'unique' => PageView::query()->whereDate('visited_at', $day)->distinct('visitor_id')->count('visitor_id'),
                'usage' => ToolUsage::query()->whereDate('created_at', $day)->count(),
            ];
        })->filter()->values();

        $stats = Analytics::stats(request()->query('range', '30days'));

        $topPages = PageView::query()
            ->selectRaw('page_url, COUNT(*) as views, COUNT(DISTINCT visitor_id) as unique_visitors')
            ->where('visited_at', '>=', $since)
            ->groupBy('page_url')
            ->orderByDesc('views')
            ->limit(10)
            ->get();

        $landingPages = PageView::query()
            ->selectRaw('page_url, COUNT(*) as entries')
            ->where('visited_at', '>=', $since)
            ->whereIn('visitor_id', function ($q) use ($since) {
                $q->select('id')->from('visitors')->where('first_seen_at', '>=', $since);
            })
            ->groupBy('page_url')
            ->orderByDesc('entries')
            ->limit(10)
            ->get();

        $referrers = PageView::query()
            ->selectRaw("COALESCE(referrer_domain, '(direct)') as source, COUNT(*) as views")
            ->where('visited_at', '>=', $since)
            ->groupBy('referrer_domain')
            ->orderByDesc('views')
            ->limit(10)
            ->get();

        $devices = PageView::query()
            ->selectRaw("COALESCE(device_type, 'unknown') as device, COUNT(*) as views")
            ->where('visited_at', '>=', $since)
            ->groupBy('device_type')
            ->orderByDesc('views')
            ->get();

        $browsers = PageView::query()
            ->selectRaw("COALESCE(browser, 'unknown') as browser, COUNT(*) as views")
            ->where('visited_at', '>=', $since)
            ->groupBy('browser')
            ->orderByDesc('views')
            ->limit(8)
            ->get();

        $countries = PageView::query()
            ->join('visitors', 'visitors.id', '=', 'page_views.visitor_id')
            ->selectRaw("COALESCE(NULLIF(page_views.country_code, ''), NULLIF(visitors.locale_country, '')) as country, COUNT(*) as views, COUNT(DISTINCT page_views.visitor_id) as visitors")
            ->where('page_views.visited_at', '>=', $since)
            ->groupBy('country')
            ->orderByDesc('views')
            ->limit(40)
            ->get()
            ->filter(fn ($row) => filled($row->country) && $row->country !== 'unknown')
            ->values();

        // Traffic sources: bucket referrer domains into direct/search/social/referral
        $searchEngines = ['google.', 'bing.', 'yahoo.', 'duckduckgo.', 'baidu.', 'yandex.', 'ecosia.', 'ask.', 'startpage.'];
        $socialSites = ['facebook.', 'twitter.', 't.co', 'x.com', 'instagram.', 'linkedin.', 'pinterest.', 'reddit.', 'tiktok.', 'youtube.', 'whatsapp.', 'quora.', 'threads.'];

        $classify = function (?string $domain) use ($searchEngines, $socialSites): string {
            if (blank($domain)) {
                return 'direct';
            }
            foreach ($searchEngines as $s) {
                if (str_contains($domain, $s)) {
                    return 'search';
                }
            }
            foreach ($socialSites as $s) {
                if (str_contains($domain, $s)) {
                    return 'social';
                }
            }

            return 'referral';
        };

        $domains = PageView::query()
            ->where('visited_at', '>=', $since)
            ->selectRaw("COALESCE(referrer_domain, '(direct)') as domain, COUNT(*) as views")
            ->groupBy('referrer_domain')
            ->orderByDesc('views')
            ->limit(200)
            ->get();

        $sourceCounts = ['direct' => 0, 'search' => 0, 'social' => 0, 'referral' => 0];
        foreach ($domains as $row) {
            $sourceCounts[$classify($row->domain === '(direct)' ? null : $row->domain)] += (int) $row->views;
        }
        arsort($sourceCounts);

        $topReferrerSites = $domains->filter(fn ($r) => $r->domain !== '(direct)')->take(8);

        return view('admin.analytics.index', [
            'stats' => $stats,
            'daily' => $daily,
            'topPages' => $topPages,
            'landingPages' => $landingPages,
            'referrers' => $referrers,
            'devices' => $devices,
            'browsers' => $browsers,
            'countries' => $countries,
            'sourceCounts' => $sourceCounts,
            'topReferrerSites' => $topReferrerSites,
            'range' => request()->query('range', '30days'),
        ]);
    }
}
