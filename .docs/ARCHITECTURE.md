# Architecture

## Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 13.42, PHP 8.3, MySQL 8 (utf8mb4) |
| Frontend | Blade (SSR), Tailwind CSS 4 (CSS-first `@theme` tokens), Alpine.js, vanilla ES modules |
| Build | Vite 7 + laravel-vite-plugin; `npm run build` locally, compiled `public/build` shipped to production |
| Charts | Chart.js 4 (`resources/js/charts.js`, loaded only on admin analytics pages) |
| Tool libraries | pdf-lib (PDF merge/split, dynamically imported per tool), qrcode (QR), Canvas API (image tools), WebCrypto (`crypto.randomUUID`) |

## Directory map (application code)

```
app/
  Console/Commands/
    AggregateAnalytics.php     # daily aggregates + retention cleanup
    GenerateDailyBlog.php      # AI draft generation (automation)
    VisitorCleanup.php         # orphan visitor pruning helper
  Http/
    Controllers/
      Admin/  (17 controllers)
      Public/ (HomeController, ToolsController, SlugResolverController, BlogController,
               PageController, SitemapController, ToolUsageController, AdClickController)
    Middleware/
      EnsureAdmin.php      # auth + active-account gate for /admin
      SecurityHeaders.php  # nosniff, frame, referrer, permissions policy
      TrackVisitor.php     # page-view tracking (auto page-type detection)
  Models/                  # 21 Eloquent models
  Services/Ai/
    AiProviderService.php       # OpenAI-compatible chat client
    BlogGeneratorService.php    # prompt → JSON article → validated draft
  Support/
    Analytics.php      # hashing, classification, recording, stats queries
    AdRenderer.php     # placement targeting + caching
    SeoResolver.php    # layered SEO fallbacks
    ToolRegistry.php   # safe component allowlist
  Traits/HasSeoMeta.php
resources/
  css/app.css          # Tailwind 4 design system (@theme + component classes)
  js/
    app.js             # Alpine bootstrap + tool loader
    analytics.js       # usage beacon
    charts.js          # Chart.js helpers (admin only)
    tools-loader.js    # dynamic import by data-tool attribute
    tools/*.js         # 12 tool modules (+ image-tool.js shared UI)
  views/
    layouts/{public,tool,admin}.blade.php
    components/{icon,tool-card}.blade.php
    partials/{seo-head,breadcrumbs,ad-slot}.blade.php
    tools/*.blade.php  (12 tool pages)
    blog/, pages/, seo/, errors/
    admin/**           (~30 admin views)
routes/
  web.php              # public + admin + protected scheduler endpoint
  console.php          # scheduler definitions
```

## URL scheme

- Explicit routes win: `/`, `/tools`, `/about`, `/contact`, `/privacy-policy`, `/terms`, `/disclaimer`, `/blog…`, `/sitemap.xml`, `/robots.txt`, `/api/tool-usage`, `/ad-click/{ad}`, `/run-scheduler/{secret}`, `/admin/**`.
- **Slug resolver (LAST route):** `GET /{slug}` resolves in order: static page → category → active tool (whose `component_key` must exist in the registry) → 404.

## Request lifecycle (public page)

1. `SecurityHeaders` → session/CSRF/cookies (web group) → `TrackVisitor` (skips `/admin*`, `/api*`, bots, non-200).
2. Controller loads data (eager-loaded to avoid N+1), resolves SEO via `SeoResolver` (entity meta → model columns → site defaults).
3. `AdRenderer` resolves active, scheduled, targeted ads per placement (5-min cache) — failures never break rendering.
4. Blade renders full SSR HTML incl. `partials/seo-head` (title, description, canonical, OG/Twitter, robots) and JSON-LD.

## Tool execution flow (100% client-side)

`data-tool="{component_key}"` on the page mount → `tools-loader.js` dynamically imports `resources/js/tools/{key}.js` (Vite code-splits; pdf-lib loads only on PDF tools) → module `init(el)` wires UI → on completion posts a beacon to `/api/tool-usage` (CSRF-protected, throttled 60/min) → recorded in `tool_usage`.

## Frontend asset pipeline

- `resources/css/app.css` — Tailwind 4 `@theme` design tokens (brand palette, radii, shadows) + component classes (`btn-*`, `card`, `input`, `badge-*`, `prose-article`, `dropzone`).
- Tool modules are dynamically imported → per-tool chunks (1–3 kB); `pdf-lib` chunk (438 kB) loads only when a PDF tool runs.
- `charts.js` is a separate Vite entry consumed only by admin analytics pages.

## Scheduling (no daemons)

`routes/console.php` registers:
- `blog:auto-pipeline` every five minutes: at most one bounded article attempt per due publishing slot (slots/limit/timezone from `automation_settings`), `withoutOverlapping` + `onOneServer`.
- `blog:publish-scheduled` every minute, same guards.
- `analytics:aggregate --cleanup` daily at 03:15, same guards.
- `blog:cleanup-history` weekly Mondays 04:45, same guards.

Hosts trigger `schedule:run` per cron minute, or the protected `GET /run-scheduler/{secret}` endpoint (secret from `SCHEDULER_SECRET`, throttled 2/min).

## Caching

- `site_settings` cached forever, flushed on save.
- Ad placements cached 5 minutes, flushed per placement on ad changes.
- Category/tool/blog slugs cached 10 minutes for page-type detection.
- Config/routes/views cacheable on production (`php artisan optimize`).
