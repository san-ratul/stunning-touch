# Project Overview

**Stunning Touch** (stunningtouch.com) is an advertising-supported, free online tools platform. The complete MVP was built fresh on Laravel 13 for deployment to generic shared hosting (cPanel, Apache/LiteSpeed, no SSH, no production Node.js).

## Product goals

1. Free, fast, private browser-based tools for a global English-speaking audience.
2. Organic search growth through server-rendered, per-page SEO.
3. Monetization via a universal, admin-managed advertisement system.
4. Content marketing through a blog with AI-assisted drafting (human-reviewed).

## Feature map

### Public website
- Homepage: hero, featured tools, category sections, value props, ad slots (top/content/bottom).
- `/tools` — full tool directory; `/tools/search?q=` — live JSON search.
- Category pages at root level: `/image-tools`, `/pdf-tools`, `/text-tools`, `/developer-tools`, `/generators` (DB-driven, admin can add more).
- Tool pages at root level: `/{tool-slug}` — interactive tool, how-to steps, FAQ accordion, related tools, 4 ad slots, sticky sidebar.
- Blog: `/blog`, `/blog/{slug}`, `/blog/category/{slug}` with featured post, related posts, reading time, share buttons, related tools.
- Static pages: `/about`, `/contact` (form + honeypot), `/privacy-policy`, `/terms`, `/disclaimer`.
- Custom 404/429/5xx error pages consistent with brand design.
- Sticky header with mobile menu and full-screen tool search (Alpine.js).
- Footer with category/company/legal links + sitemap link.

### Tools platform
- 12 fully functional tools (see TOOLS_CATALOG.md). All client-side processing; files never leave the visitor's device.
- Database-driven catalog: categories, tools, ordering, featured flags, active/archive states, icons, how-to steps, FAQs.
- `app/Support/ToolRegistry.php` — hardcoded allowlist mapping `component_key` → Blade view + JS module. DB rows can never execute arbitrary code.

### SEO system
- Per-entity SEO rows (polymorphic `seo_meta` table) for tools, categories, blog categories, static pages; blog posts keep SEO in dedicated columns.
- Admin SEO Manager lists every real page; edit titles, meta descriptions, keywords, canonical, OG, Twitter, robots, schema type/data.
- Technical SEO: SSR metadata in initial HTML, canonical tags, unique `<title>` per page, OG/Twitter cards, JSON-LD (WebPage breadcrumbs, Article), XML sitemap, robots.txt, semantic HTML5, breadcrumb nav, internal linking (related tools, blog→tools).

### Analytics (privacy-conscious)
- `visitors` identified by daily-rotated SHA-256 hash of IP+UA+app-key. Raw IPs are never stored.
- `page_views`: URL, page type, referrer domain (internal stripped), device, browser, country (edge header when present).
- Bots/API/admin paths excluded. Dashboard charts (Chart.js) with 1/7/30/90-day filters: totals, uniques, returning, top pages, landing pages, referrers, devices, browsers, countries.

### Tool statistics
- `tool_usage` rows recorded ONLY when a tool actually completes (or fails) processing — never on page views.
- Per-tool totals, success/fail, unique users, success rate; daily trend chart; 7/30/90-day ranges.
- `tool_usage_daily` + `analytics_daily` aggregates via `analytics:aggregate`.

### Advertisements
- 11 seeded placements; unlimited ads; any provider; code or banner type; page/tool/device targeting; scheduling; priority; enable/disable; impression + internal-click counters; CSP-protected sandboxed preview; 5-minute render cache invalidated on save.

### Blog CMS
- Posts with HTML content, excerpts, featured images (media library picker), reading time, view counts.
- Statuses: draft → scheduled → published → archived. Scheduled posts go live only when due (public queries filter by `published_at <= now()`).
- Blog categories + tags, per-post SEO fields, related posts, related tools (keyword-matched).

### AI integration
- Any OpenAI-compatible endpoint; settings (endpoint/model/temperature/max tokens) + encrypted API key stored in DB.
- Generation workflow: topic → structured JSON article → validated → saved as **draft** (`source=ai`) → admin review → publish. Auto-publish is off by default.
- Never fabricates statistics/research; featured images are never AI-invented (admin attaches from media library).
- Automation: daily/weekly schedule, preferred time + timezone, category/topic/keyword preferences, per-run dedupe.

### Admin dashboard (17 modules)
Dashboard (10 KPI cards + trend chart + top tools + recent activity), Tools, Categories, SEO Manager, Visitor Analytics, Tool Statistics, Advertisements, Blog Posts/Categories/Tags, AI Provider, Automation, Media, Static Pages, Site Settings, Admin Users, Audit Logs, Contact Messages.

## Out of scope (by design)
- Public user registration, payments, SSH/queue workers/Redis/WebSockets — kept off to satisfy shared-hosting constraints.
