<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read-side queries for the bot protection dashboard.
 *
 * All numbers come exclusively from recorded bot_traffic_metrics /
 * bot_event_log rows or existing human analytics tables — nothing is
 * estimated or fabricated. Category totals (e.g. "Suspicious") and
 * outcome totals (e.g. "Blocked") intentionally overlap and are labelled
 * as such in the dashboard.
 */
class BotAnalytics
{
    public const RANGES = ['24h' => 1, '7d' => 7, '30d' => 30];

    /** @return array{from: CarbonInterface, to: CarbonInterface, hourly: bool} */
    public static function window(string $range): array
    {
        $days = self::RANGES[$range] ?? 1;

        return [
            'from' => now()->subDays($days)->startOfDay(),
            'to' => now(),
            'hourly' => $days === 1,
        ];
    }

    public static function summary(string $range): array
    {
        ['from' => $from, 'to' => $to] = self::window($range);

        $rows = DB::table('bot_traffic_metrics')
            ->where(function ($q) use ($from, $to) {
                $q->where('date', '>=', $from->toDateString())
                    ->where('date', '<=', $to->toDateString());
            })
            ->get();

        $botCategories = [TrafficVerdict::VERIFIED_SEARCH_BOT, TrafficVerdict::KNOWN_BOT, TrafficVerdict::SUSPICIOUS];

        $sum = fn ($filter) => $rows->filter($filter)->sum('requests');

        return [
            'total_bot' => $sum(fn ($r) => in_array($r->category, $botCategories, true)),
            'verified' => $sum(fn ($r) => $r->category === TrafficVerdict::VERIFIED_SEARCH_BOT),
            'known' => $sum(fn ($r) => $r->category === TrafficVerdict::KNOWN_BOT),
            'suspicious' => $sum(fn ($r) => $r->category === TrafficVerdict::SUSPICIOUS),
            'unknown' => $sum(fn ($r) => $r->category === TrafficVerdict::UNKNOWN),
            'allowed_bots' => $sum(fn ($r) => in_array($r->category, $botCategories, true) && $r->action === BotMetrics::ACTION_ALLOWED),
            'blocked' => $sum(fn ($r) => $r->action === BotMetrics::ACTION_BLOCKED),
            'rate_limited' => $sum(fn ($r) => $r->action === BotMetrics::ACTION_RATE_LIMITED),
            'duplicates' => $sum(fn ($r) => $r->action === BotMetrics::ACTION_DUPLICATE_REJECTED),
        ];
    }

    /**
     * Requests over time. Hourly buckets for the 24h view, daily otherwise.
     *
     * @return list<array{bucket: string, detected: int, blocked: int, rate_limited: int, unknown: int, allowed: int}>
     */
    public static function series(string $range): array
    {
        ['from' => $from, 'to' => $to, 'hourly' => $hourly] = self::window($range);

        $rows = DB::table('bot_traffic_metrics')
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<=', $to->toDateString())
            ->get();

        $botCategories = [TrafficVerdict::VERIFIED_SEARCH_BOT, TrafficVerdict::KNOWN_BOT, TrafficVerdict::SUSPICIOUS];

        $buckets = [];

        $cursor = $from->copy();
        while ($cursor <= $to) {
            $buckets[$hourly ? $cursor->format('Y-m-d H') : $cursor->toDateString()] = [
                'bucket' => $hourly ? $cursor->format('Y-m-d H') : $cursor->toDateString(),
                'detected' => 0,
                'blocked' => 0,
                'rate_limited' => 0,
                'unknown' => 0,
                'allowed' => 0,
            ];
            $cursor = $hourly ? $cursor->addHour() : $cursor->addDay();
        }

        foreach ($rows as $row) {
            $key = $hourly ? $row->date.' '.sprintf('%02d', $row->hour) : $row->date;

            if (! isset($buckets[$key])) {
                continue;
            }

            if ($row->category === TrafficVerdict::UNKNOWN) {
                $buckets[$key]['unknown'] += (int) $row->requests;

                continue;
            }

            if (in_array($row->category, $botCategories, true)) {
                $buckets[$key]['detected'] += (int) $row->requests;
            }

            if ($row->action === BotMetrics::ACTION_BLOCKED) {
                $buckets[$key]['blocked'] += (int) $row->requests;
            }

            if ($row->action === BotMetrics::ACTION_RATE_LIMITED) {
                $buckets[$key]['rate_limited'] += (int) $row->requests;
            }

            if ($row->action === BotMetrics::ACTION_ALLOWED && in_array($row->category, $botCategories, true)) {
                $buckets[$key]['allowed'] += (int) $row->requests;
            }
        }

        return array_values($buckets);
    }

    /** Observed bot categories (never fabricated): requests per family. */
    public static function categories(string $range): array
    {
        ['from' => $from, 'to' => $to] = self::window($range);

        return DB::table('bot_traffic_metrics')
            ->selectRaw('category, NULLIF(bot_name, \'\') as bot_name, SUM(requests) as requests, SUM(CASE WHEN action = ? THEN requests ELSE 0 END) as blocked', [BotMetrics::ACTION_BLOCKED])
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<=', $to->toDateString())
            ->groupBy('category', 'bot_name')
            ->orderByDesc('requests')
            ->get()
            ->all();
    }

    /** Endpoint groups receiving the most automated traffic. */
    public static function topEndpoints(string $range): array
    {
        ['from' => $from, 'to' => $to] = self::window($range);

        return DB::table('bot_traffic_metrics')
            ->selectRaw('endpoint_group, SUM(requests) as requests, SUM(CASE WHEN action = ? THEN requests ELSE 0 END) as blocked, SUM(CASE WHEN action = ? THEN requests ELSE 0 END) as rate_limited', [BotMetrics::ACTION_BLOCKED, BotMetrics::ACTION_RATE_LIMITED])
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<=', $to->toDateString())
            ->groupBy('endpoint_group')
            ->orderByDesc('requests')
            ->limit(10)
            ->get()
            ->all();
    }

    /** Recent aggregated security events (no raw IPs, no request bodies). */
    public static function recentEvents(string $range, int $limit = 30): array
    {
        ['from' => $from, 'to' => $to] = self::window($range);

        return DB::table('bot_event_log')
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<=', $to->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('hour')
            ->orderByDesc('requests')
            ->limit($limit)
            ->get()
            ->all();
    }

    /** Human page views in range (for the human vs automated chart). */
    public static function humanPageViews(string $range): int
    {
        ['from' => $from, 'to' => $to] = self::window($range);

        return DB::table('page_views')->where('visited_at', '>=', $from)->count();
    }
}
