# Analytics & Tool Statistics

## Privacy model
- Visitors are identified by `SHA-256(IP + user-agent + APP_KEY + date)` — **raw IPs are never stored** and the hash rotates daily so it cannot track people across days.
- Bots/crawlers (user-agent patterns), empty UAs, `/admin*`, `/api*` and non-200 responses are never tracked.
- Tool inputs and uploaded files are never collected.
- Country comes from edge headers (`CF-IPCountry` / `X-Country-Code`) when the host provides them; otherwise it stays empty (no IP geolocation by design).
- Retention: raw `page_views`/`tool_usage` rows older than the configured retention (Site Settings → “Analytics Retention Days”, default 90) are deleted by `analytics:aggregate --cleanup`.

## Visitor Analytics (Admin → Visitor Analytics)
Ranges: Today / 7 / 30 / 90 days / **1 Year**. Real data only — no fake numbers are ever generated.

- KPI cards: total visitors, unique visitors, page views, returning visitors, tool runs.
- Daily trend chart: page views, unique visitors, tool runs (Chart.js, smooth curves + gradient fill).
- Distribution charts: devices (desktop/mobile/tablet), browsers.
- **Traffic sources**: doughnut chart + per-source counts classifying referrers into Direct / Search engines / Social media / Referrals (e.g. google → Search, facebook → Social, empty referrer → Direct), plus a top referrer sites table.
- **Countries**: horizontal bar chart of the top 10 countries (views + unique visitors per country) and a country list.
- **Visitors world map**: an SVG choropleth world map (d3-geo, bundled locally — no CDN or external tiles). Every country with data is a hit zone: hovering shows a floating tooltip with the country flag, name, visitor count and page-view count; the hovered country is highlighted. Color intensity = visitor count (brand gradient), countries without data stay neutral gray.
- Top tables: top pages (views + uniques), landing pages, referrer sources (`(direct)` when empty), countries.
- Filters preserve state via query string (`?range=7days`).

### How country data is obtained (privacy-safe)
1. **Edge geo headers** — `CF-IPCountry` / `X-Country-Code` when the host provides them (authoritative, per page view).
2. **Browser-locale hint** — when no edge header exists, the visitor's browser sends its locale region (e.g. `en-US` → `US`) **once per session** to `/api/visitor-locale`; stored on the visitor row as an approximate value. This is clearly labeled "approximate" in the UI.

No IP-based geolocation is ever performed; no raw IPs are stored.

## Tool Statistics (Admin → Tool Statistics)
- KPI cards: total runs, successful, failed, success rate, unique users.
- Daily runs bar chart.
- Per-tool table: total, success, failed, unique users, success-rate badges — covering **every tool**, including those with zero usage.

**Important:** a "tool run" is counted only when the browser beacon fires after actual processing. Page loads do not count. Failed operations (e.g. invalid JSON) are recorded as failures and lower the success rate — by design.

## Data flow
```
Browser → POST /api/tool-usage {tool, success, event} (CSRF + throttle 60/min)
        → ToolRegistry key check → tool_usage row (visitor id when known)
Daily cron → analytics:aggregate [--cleanup]
        → analytics_daily + tool_usage_daily summaries → prune raw rows
```

## Aggregation command
```bash
php artisan analytics:aggregate              # summarize yesterday
php artisan analytics:aggregate --date=2026-11-14
php artisan analytics:aggregate --cleanup    # also prune raw data by retention
```
