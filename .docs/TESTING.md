# Testing

## Suites
- **Unit** (`tests/Unit`): ToolRegistry, SeoResolver fallbacks, device/browser classification, referrer normalization, reading time, AI provider behavior (endpoint building, error handling), full AI generation workflow via faked HTTP.
- **Feature** (`tests/Feature`): public pages, all 12 tool pages (unique titles + canonicals), tool activation/archiving effects, usage beacon, visitor tracking + privacy hashing, bot exclusion, aggregation command, admin auth (redirects, login, rate limit, inactive accounts), tools/categories/SEO/blog/ads/media/users management workflows, draft→publish→schedule visibility, ad targeting/scheduling/preview CSP, sitemap/robots, noindex, single-title invariant, custom 404.

Run:

```bash
php artisan test
```

Uses **SQLite in-memory** (`phpunit.xml`) — the development/production MySQL database is never touched.

## Verified manually during development
- All 31 public/admin URLs HTTP-status-checked via scripted smoke tests (see final report).
- Authenticated admin walkthrough: login → all 17 module pages → create/edit flows.
- Tool usage beacon end-to-end (public page token → API → DB row with correct tool).
- Analytics recording with real page types (home/tools_index/category/tool/blog_*/static_page) and device/browser classification.
- Production build (`npm run build`) completes; per-tool JS chunks verified in manifest.

## Results
```
Tests:    60 passed (262 assertions)
Duration: ~5s
```

## Notes for contributors
- Tests that create posts/tools rely on the DatabaseSeeder — `RefreshDatabase` + `seed` are used consistently.
- AI tests use `Http::fake` — no network calls, no real credentials needed.
- When adding a tool: extend `ToolRegistry::keys()` usage in `ToolsTest` (the suite enforces 12 tools + unique titles).
