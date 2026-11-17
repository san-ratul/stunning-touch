# Bot Detection, Blocking & Analytics

Application-managed bot protection for shared hosting: **no Cloudflare, no
Redis, no SSH, no queue workers**. Detection and enforcement are separate
concerns and are measured separately.

## Design principles

1. **Detection ≠ blocking.** A detected bot is not automatically a blocked
   bot. Every recorded counter reflects what actually happened.
2. **Server-side classification only.** Client-submitted values such as
   `is_bot`, `is_human`, `bot_type` or `traffic_classification` are never
   consulted — the server decides.
3. **User-Agent is never proof of identity.** A fake Googlebot User-Agent is
   verified with **forward-confirmed reverse DNS** (`App\Support\ReverseDnsCrawlerVerifier`);
   results are cached (verified: 24 h, failed: 1 h) to avoid repeated DNS
   lookups. An unverified claim is treated as suspicious automation, and when
   verification is unavailable the request is treated as a known automated
   client (fail-safe: never human, never falsely verified).
4. **At most one final enforcement outcome per request.** Metrics never
   double-count across middleware layers.
5. **No writes for ordinary human page views.** Human traffic is classified
   by a few regex checks; only automated, suspicious or rejected requests
   produce one atomic hourly upsert.

## Classification (`App\Support\TrafficClassifier`)

| Category | Meaning | Examples |
|---|---|---|
| `human` | real-browser user agent | Chrome, Firefox, Safari, Edge |
| `verified_search_bot` | claimed search crawler whose client IP passed reverse-DNS verification | Googlebot, Bingbot |
| `known_bot` | recognized automated client | curl, AhrefsBot, GPTBot, headless Chrome, monitors, social preview bots |
| `suspicious` | crawler claims failing verification, honeypot submissions | fake Googlebot, form spam |
| `unknown` | unrecognizable or empty user agent | scripted clients; never relabelled as human |

Every verdict carries a machine-readable `reason` (e.g.
`crawler-claim-unverified`, `honeypot`, `browser-user-agent`).

## Enforcement policies (`App\Http\Middleware\BotGuard`)

Global middleware — wraps the whole stack so route-level `throttle` 429s are
observed (Laravel priority-sorts `ThrottleRequests` ahead of appended web
middleware).

| Traffic | GET pages | Protected POST endpoints (login, contact, tool-usage, SMTP) |
|---|---|---|
| Human | allowed | allowed (existing throttles) |
| Verified search bot | allowed, counted, generous per-IP rate limit | 403 blocked |
| Known bot | allowed, counted, per-IP rate limit (60/min default) | 403 blocked |
| Suspicious | per-IP rate limit (30/min default) → 429 | 403 blocked |
| Unknown | allowed, counted (never human) | allowed (existing throttles) |

- 403 = policy rejection, 429 = rate limiting (with `Retry-After`).
- Every 429 produced anywhere (including human bursts against existing
  endpoint throttles) is counted exactly once.
- The contact honeypot still behaves exactly as before (validation error);
  the submission is additionally counted as a blocked suspicious request.
- `/admin/*` traffic is not classified except the login attempt surface.
- Existing SMTP limits are untouched: connection test 10/min + 50/day,
  email send 3/hour + 10/day.
- The `/tools/search` endpoint now carries `throttle:30,1`.
- Tool usage beacons (`trackToolUsage`) are deduplicated: identical
  (visitor, tool, event, success) submissions inside a 60 s window are
  rejected and counted as `duplicate_rejected`; automated usage never
  enters human tool analytics.

## Storage (performance-conscious)

- `bot_traffic_metrics` — hourly aggregate buckets:
  `(date, hour, category, bot_name, endpoint_group, action) → requests`,
  incremented by one atomic `INSERT ... ON DUPLICATE KEY UPDATE`.
- `bot_event_log` — significant events only (blocked / rate-limited /
  duplicates), same hourly aggregation plus `reason` and `last_seen_at`.
- No raw IPs anywhere in the bot tables. No request bodies, no
  passwords or tokens. No per-request event rows.
- Retention (via `analytics:aggregate --cleanup` on the daily 03:15
  schedule): bot event buckets pruned after 30 days, metrics after 180 days.
- Works on the `database` cache driver; no Redis, no queue workers.
  Failures degrade safely: a metrics failure never changes enforcement.

## Admin dashboard

**Admin → Insights → Bot Protection** (`/admin/bot-protection`):

- KPI cards: total bot requests, blocked, allowed bot requests,
  rate-limited, suspicious, unknown, verified search bots, duplicate
  events rejected. Category totals (e.g. "Suspicious") intentionally
  overlap with outcome totals ("Blocked") — labelled in the UI.
- Charts: automated traffic over time (hourly for 24 h view, daily
  otherwise), blocked vs allowed, human vs automated requests.
- Observed bot categories, top targeted endpoints, recent aggregated
  security activity (timestamp, category, family, endpoint, action,
  reason, count).
- Date filters: last 24 hours / 7 days / 30 days.
- Protection settings (authorized admins only): enable/disable with
  explicit confirmation, block automated POSTs, page & suspicious rate
  limits (bounded server-side ranges). Settings changes are audit-logged.

## Measurement scope (important)

These metrics cover **application-level** traffic only — requests that
reached Laravel. Requests rejected earlier by `.htaccess`, ModSecurity,
Apache, or the hosting firewall die before PHP executes and **cannot be
measured from this application**; they are never estimated or fabricated.
Use the cPanel control panel (Raw Access Logs, ModSecurity logs) for that
layer. The dashboard states this explicitly.

## Configuration

Initial defaults come from `config/bot.php` / env (`BOT_*`); runtime
overrides live in `site_settings` via the admin UI.
