<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Hourly aggregate counters for bot detection and enforcement.
 *
 * One atomic upsert per recorded request — no per-human page-view writes,
 * no unbounded request rows. All failures are swallowed: bot protection
 * must never break the site, and a metrics failure must not change
 * enforcement behaviour.
 *
 * Actions:
 * - allowed          — automated request intentionally permitted.
 * - blocked          — request actually rejected by this application (403
 *                      or a policy/honeypot rejection).
 * - rate_limited     — request rejected because a configured rate limit
 *                      was exceeded (429).
 * - unknown_seen     — unclassified traffic observed on monitored
 *                      endpoints (allowed, kept out of human counts).
 * - duplicate_rejected — duplicate analytics beacon rejected.
 */
class BotMetrics
{
    public const ACTION_ALLOWED = 'allowed';

    public const ACTION_BLOCKED = 'blocked';

    public const ACTION_RATE_LIMITED = 'rate_limited';

    public const ACTION_UNKNOWN_SEEN = 'unknown_seen';

    public const ACTION_DUPLICATE_REJECTED = 'duplicate_rejected';

    /** Significant actions that also land in the event log. */
    protected const LOGGED_ACTIONS = [
        self::ACTION_BLOCKED,
        self::ACTION_RATE_LIMITED,
        self::ACTION_DUPLICATE_REJECTED,
    ];

    public static function record(TrafficVerdict $verdict, string $endpointGroup, string $action): void
    {
        self::recordNamed($verdict->category, (string) $verdict->botName, $endpointGroup, $action, $verdict->reason);
    }

    public static function recordNamed(string $category, string $botName, string $endpointGroup, string $action, ?string $reason = null): void
    {
        try {
            $now = now();
            $date = $now->toDateString();
            $hour = (int) $now->format('G');
            $botName = mb_substr($botName, 0, 64);
            $endpointGroup = mb_substr($endpointGroup, 0, 32);
            $action = mb_substr($action, 0, 32);

            self::upsertMetrics($date, $hour, $category, $botName, $endpointGroup, $action);

            if (in_array($action, self::LOGGED_ACTIONS, true) && $reason !== null) {
                self::upsertEventLog($date, $hour, $category, $botName, $endpointGroup, $action, mb_substr($reason, 0, 64), $now);
            }
        } catch (\Throwable) {
            // Metrics must never break the request or change enforcement.
        }
    }

    /**
     * Guard against a single request being counted twice when it passes
     * through multiple layers (e.g. guard rate limit + response watcher).
     */
    public static function markCounted($request, string $action): void
    {
        try {
            $request->attributes->set('bot.counted', $action);
        } catch (\Throwable) {
        }
    }

    public static function isCounted($request, ?string $action = null): bool
    {
        try {
            $counted = $request->attributes->get('bot.counted');
        } catch (\Throwable) {
            return false;
        }

        return $counted !== null && ($action === null || $counted === $action);
    }

    protected static function upsertMetrics(string $date, int $hour, string $category, string $botName, string $endpointGroup, string $action): void
    {
        $connection = DB::connection();
        $table = $connection->getTablePrefix().'bot_traffic_metrics';

        $values = [$date, $hour, $category, $botName, $endpointGroup, $action];

        if ($connection->getDriverName() === 'sqlite') {
            $sql = 'insert into '.$table.' ("date", "hour", "category", "bot_name", "endpoint_group", "action", "requests")'
                .' values (?, ?, ?, ?, ?, ?, 1)'
                .' on conflict ("date", "hour", "category", "bot_name", "endpoint_group", "action")'
                .' do update set "requests" = "requests" + 1';
        } else {
            $sql = 'insert into '.$table.' (`date`, `hour`, `category`, `bot_name`, `endpoint_group`, `action`, `requests`)'
                .' values (?, ?, ?, ?, ?, ?, 1)'
                .' on duplicate key update `requests` = `requests` + 1';
        }

        $connection->statement($sql, $values);
    }

    protected static function upsertEventLog(string $date, int $hour, string $category, string $botName, string $endpointGroup, string $action, string $reason, $now): void
    {
        $connection = DB::connection();
        $table = $connection->getTablePrefix().'bot_event_log';

        $values = [$date, $hour, $category, $botName, $endpointGroup, $action, $reason, $now];

        if ($connection->getDriverName() === 'sqlite') {
            $sql = 'insert into '.$table.' ("date", "hour", "category", "bot_name", "endpoint_group", "action", "reason", "requests", "last_seen_at")'
                .' values (?, ?, ?, ?, ?, ?, ?, 1, ?)'
                .' on conflict ("date", "hour", "category", "bot_name", "endpoint_group", "action", "reason")'
                .' do update set "requests" = "requests" + 1, "last_seen_at" = excluded."last_seen_at"';

            $connection->statement($sql, [$date, $hour, $category, $botName, $endpointGroup, $action, $reason, $now]);
        } else {
            $sql = 'insert into '.$table.' (`date`, `hour`, `category`, `bot_name`, `endpoint_group`, `action`, `reason`, `requests`, `last_seen_at`)'
                .' values (?, ?, ?, ?, ?, ?, ?, 1, ?)'
                .' on duplicate key update `requests` = `requests` + 1, `last_seen_at` = values(`last_seen_at`)';

            $connection->statement($sql, [$date, $hour, $category, $botName, $endpointGroup, $action, $reason, $now]);
        }
    }
}
