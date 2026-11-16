# Cron Setup (shared hosting / cPanel)

The application needs one cron entry that hits the protected scheduler
endpoint every minute (no shell/SSH or long-running processes required).

## 1. Choose a scheduler secret

In `.env` (never committed):

```
SCHEDULER_SECRET=long-random-string
```

## 2. cPanel cron job

cPanel → Cron Jobs → twice per minute is not available; use every minute:

```
* * * * * /usr/bin/curl -s -m 55 "https://stunningtouch.com/run-scheduler/YOUR_SECRET" > /dev/null
```

or with wget:

```
* * * * * /usr/bin/wget -q -O /dev/null --timeout=55 "https://stunningtouch.com/run-scheduler/YOUR_SECRET"
```

The endpoint is throttled (2 requests/minute), returns 404 on a wrong secret,
and executes `schedule:run` internally. Scheduled jobs lock with
`withoutOverlapping()` + `onOneServer()` so overlapping ticks are safe.

## 3. Alternative: system cron with Artisan

If you have shell cron access:

```
* * * * * cd /path/to/app && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

## What runs on the schedule

| Job | Frequency | Purpose |
|---|---|---|
| `blog:auto-pipeline` | every 5 min | one bounded autonomous article attempt per due slot |
| `blog:publish-scheduled` | every minute | publish due scheduled posts |
| `analytics:aggregate --cleanup` | daily 03:15 | analytics rollup/cleanup |
| `blog:cleanup-history` | weekly Mon 04:45 | prune generation history (published content never deleted) |

## PHP execution limits

Pipeline invocations are bounded (one attempt each), but a single AI request
can run minutes. PHP `max_execution_time` should be at least ~900 s for CLI
cron runs (CLI usually unrestricted). On FPM/web contexts the scheduler
endpoint only triggers `schedule:run`, which starts commands as child
processes — keep `request_terminate_timeout` generous or prefer CLI cron.
