<?php

namespace App\Support;

use App\Models\BlogCategory;
use App\Models\Category;
use App\Models\PageView;
use App\Models\Tool;
use App\Models\ToolUsage;
use App\Models\Visitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Privacy-conscious visitor analytics.
 *
 * - No raw IP addresses are ever stored: visitors are identified by a
 *   daily-rotated salted hash of (IP + user agent + salt).
 * - No uploaded files or tool inputs are collected.
 * - Country comes from edge headers (CF-IPCountry / X-Country-Code) when
 *   the host provides them; otherwise null.
 */
class Analytics
{
    public const BOT_PATTERN = '/(bot|crawler|spider|slurp|bing|yandex|duckduck|facebookexternalhit|linkedin|preview|monitor|uptime|curl|wget|python-requests|axios|headless|lighthouse|pagespeed)/i';

    public static function shouldTrack(Request $request): bool
    {
        if ($request->is('admin/*') || $request->is('admin') || $request->is('api/*') || $request->is('_*')) {
            return false;
        }

        $ua = (string) $request->userAgent();

        if ($ua === '' || preg_match(self::BOT_PATTERN, $ua)) {
            return false;
        }

        return true;
    }

    public static function fingerprint(Request $request): string
    {
        $salt = config('app.key');

        return hash('sha256', $request->ip().'|'.$request->userAgent().'|'.$salt.'|'.now()->format('Y-m-d'));
    }

    public static function classifyDevice(Request $request): string
    {
        $ua = (string) $request->userAgent();

        return match (true) {
            (bool) preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobile))/i', $ua) => 'tablet',
            (bool) preg_match('/(mobi|iphone|ipod|android.*mobile|windows phone)/i', $ua) => 'mobile',
            default => 'desktop',
        };
    }

    public static function classifyBrowser(Request $request): string
    {
        $ua = (string) $request->userAgent();

        return match (true) {
            (bool) preg_match('/edg\//i', $ua) => 'Edge',
            (bool) preg_match('/opr\/|opera/i', $ua) => 'Opera',
            (bool) preg_match('/chrome|crios/i', $ua) => 'Chrome',
            (bool) preg_match('/firefox|fxios/i', $ua) => 'Firefox',
            (bool) preg_match('/safari(?!.*chrome)/i', $ua) => 'Safari',
            (bool) preg_match('/msie|trident/i', $ua) => 'IE',
            default => 'Other',
        };
    }

    public static function country(Request $request): ?string
    {
        foreach (['CF_IPCOUNTRY', 'HTTP_CF_IPCOUNTRY', 'X_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE'] as $key) {
            $value = $request->server($key);

            if (! empty($value) && strlen($value) === 2) {
                return strtoupper($value);
            }
        }

        return null;
    }

    public static function referrerDomain(?string $referrer): ?string
    {
        if (blank($referrer)) {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./i', '', $host));

        $siteHost = parse_url(config('app.url'), PHP_URL_HOST);

        if ($siteHost && strcasecmp($host, strtolower(preg_replace('/^www\./i', '', $siteHost))) === 0) {
            return null;
        }

        return $host;
    }

    public static function recordPageView(Request $request, string $pageType): void
    {
        if ($pageType === 'auto') {
            $pageType = self::resolvePageType($request);
        }

        if (! self::shouldTrack($request)) {
            return;
        }

        $hash = self::fingerprint($request);
        $now = now();

        $visitor = Visitor::query()->firstOrCreate(
            ['visitor_hash' => $hash],
            ['first_seen_at' => $now]
        );

        $visitor->updateQuietly(['last_seen_at' => $now]);

        $referrerDomain = self::referrerDomain($request->headers->get('referer'));

        PageView::query()->create([
            'visitor_id' => $visitor->id,
            'page_url' => substr($request->path() === '/' ? '/' : '/'.$request->path(), 0, 500),
            'page_type' => $pageType,
            'referrer' => substr((string) $request->headers->get('referer'), 0, 500) ?: null,
            'referrer_domain' => $referrerDomain,
            'device_type' => self::classifyDevice($request),
            'browser' => self::classifyBrowser($request),
            'country_code' => self::country($request),
            'visited_at' => $now,
        ]);
    }

    /**
     * Infer a page type from the URL using cached category/tool/blog slugs.
     */
    public static function resolvePageType(Request $request): string
    {
        $path = trim($request->path(), '/');

        if ($path === '') {
            return 'home';
        }

        if (str_starts_with($path, 'blog/')) {
            return str_starts_with($path, 'blog/category/') ? 'blog_category' : 'blog_post';
        }

        if ($path === 'blog') {
            return 'blog_index';
        }

        if ($path === 'tools' || str_starts_with($path, 'tools/')) {
            return 'tools_index';
        }

        $first = explode('/', $path)[0];

        $categorySlugs = cache()->remember('st_category_slugs', now()->addMinutes(10),
            fn () => Category::query()->pluck('slug')->all());

        if (in_array($first, $categorySlugs, true)) {
            return 'category';
        }

        $toolSlugs = cache()->remember('st_tool_slugs', now()->addMinutes(10),
            fn () => Tool::query()->pluck('slug')->all());

        if (in_array($first, $toolSlugs, true)) {
            return 'tool';
        }

        $blogCategorySlugs = cache()->remember('st_blog_category_slugs', now()->addMinutes(10),
            fn () => BlogCategory::query()->pluck('slug')->all());

        if (in_array($first, $blogCategorySlugs, true)) {
            return 'blog_category';
        }

        return 'static_page';
    }

    /**
     * Record an actual tool processing event. Only real operations count.
     */
    public static function recordToolUsage(string $componentKey, bool $success, string $eventType = 'run'): void
    {
        $tool = Tool::query()->where('component_key', $componentKey)->where('is_active', true)->first();

        if (! $tool) {
            return;
        }

        $request = request();
        $visitorId = null;

        if ($request && self::shouldTrack($request)) {
            $visitor = Visitor::query()->where('visitor_hash', self::fingerprint($request))->first();
            $visitorId = $visitor?->id;
        }

        ToolUsage::query()->create([
            'tool_id' => $tool->id,
            'visitor_id' => $visitorId,
            'event_type' => $eventType,
            'success' => $success,
            'created_at' => now(),
        ]);
    }

    public static function stats(string $range = '30 days'): array
    {
        $since = now()->subDays(self::daysFor($range))->startOfDay();

        $totalVisitors = Visitor::query()->count();
        $activeVisitors = Visitor::query()->where('last_seen_at', '>=', $since)->count();
        $pageViews = PageView::query()->where('visited_at', '>=', $since)->count();
        $uniqueVisitors = PageView::query()->where('visited_at', '>=', $since)->distinct('visitor_id')->count('visitor_id');

        $returning = DB::table('page_views')
            ->join('visitors', 'visitors.id', '=', 'page_views.visitor_id')
            ->where('page_views.visited_at', '>=', $since)
            ->groupBy('page_views.visitor_id')
            ->havingRaw('COUNT(*) > 1')
            ->selectRaw('page_views.visitor_id')
            ->get()
            ->count();

        return [
            'total_visitors' => $totalVisitors,
            'active_visitors' => $activeVisitors,
            'page_views' => $pageViews,
            'unique_visitors' => $uniqueVisitors,
            'returning_visitors' => $returning,
            'today' => self::pageViewsBetween(now()->startOfDay(), now()),
            'week' => self::pageViewsBetween(now()->subDays(7)->startOfDay(), now()),
            'month' => self::pageViewsBetween(now()->subDays(30)->startOfDay(), now()),
        ];
    }

    protected static function pageViewsBetween($from, $to): array
    {
        $views = PageView::query()->whereBetween('visited_at', [$from, $to])->count();
        $unique = PageView::query()->whereBetween('visited_at', [$from, $to])->distinct('visitor_id')->count('visitor_id');

        return ['views' => $views, 'unique' => $unique];
    }

    public static function daysFor(string $range): int
    {
        return match ($range) {
            'today' => 1,
            '7days' => 7,
            '90days' => 90,
            '365days' => 365,
            default => 30,
        };
    }
}
