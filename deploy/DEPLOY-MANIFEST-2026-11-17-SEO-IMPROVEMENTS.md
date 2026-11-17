# Deployment Manifest — Technical SEO Update (2026-11-17)

- **Commits:** `66a9447` (feat: allowlist schedule:list in artisan runner) +
  `90b16d3` (feat: improve technical seo and social metadata)
- **Package:** `deployment/seo-improvements-update.zip` (479.89 KB, 54 entries)
- **SHA-256:** `c66f8b67e17455bbb11a28eaf2322df6d45159ab2b6d666f30a33b83b2b64595`
- **Builder:** `deploy/build-seo-improvements-package.ps1` (rebuildable)
- **Migration:** none — the database is not touched. **Dependencies:** none
  added. **Cron:** none required.
- **Scope (six verified SEO improvements + one admin request):**
  1. Canonical host redirects: `http://*` and `www.*` → `https://stunningtouch.com`,
     single hop, path + query preserved (`RedirectCanonicalHost` middleware,
     loop-free and host-only) + guarded `.htaccess` rules in both layouts
     (`%{HTTPS}` **and** `X-Forwarded-Proto`).
  2. `public/robots.txt` (shipped static file that shadows the Laravel
     route) now carries the full policy and the canonical `Sitemap:`
     directive exactly once; the Laravel route emits canonical URLs too.
  3. Default OG image `public/og-image.png` (1200×630 brand design) with
     full fallback chains: og:image → featured image → default;
     twitter:image → og:image → default; og/twitter descriptions from
     resolved OG/Twitter fields → meta description. Canonical host forced
     via `APP_URL` in canonical tags, `og:url` and every sitemap `<loc>`
     (plus `URL::forceRootUrl` in production).
  4. HSTS `max-age=31536000` on HTTPS responses only, single header,
     no `includeSubDomains`/`preload`.
  5. Homepage meta description rewritten to 154 characters (was 167,
     truncated mid-phrase at 158).
  6. Favicons: real multi-size `favicon.ico` (the shipped one was 0 bytes),
     SVG + ICO + `apple-touch-icon.png` (180×180) declared in the layout.
  7. Admin Artisan Runner: read-only `schedule:list` added to the allowlist
     (no options, no arguments — same strictness as `route:list`).
- **Unchanged by design:** BotGuard/TrafficClassifier/bot metrics, first-party
  analytics, tool usage tracking, rate limits, admin auth/Artisan Runner
  gate, all tool implementations.
- **After upload:** replace the live root `.htaccess` (Layout 1 — the ZIP
  ships it at the package root; Layout 2 updates `public_html/.htaccess`
  manually from `deploy/root-public-htaccess.txt`), then run
  `optimize:clear` through the Admin → Artisan Runner.
- **Hosting-level manual attention:** verify no redirect loop after the
  `.htaccess` change (HTTPS rule is `X-Forwarded-Proto`-guarded; removal
  instructions in DEPLOYMENT_GUIDE.md), and keep the www certificate valid
  (it covers `www.stunningtouch.com` — verified 2026-11-17).
- **Verification:** full PHP suite 549 passed (2006 assertions) incl. 26 new
  TechnicalSeoTest cases; JS tests 88 passed; Pint passed; Vite build
  succeeded; ZIP round-trip hash-verified + independent extraction check
  (11/11 key files present, 0 forbidden entries).
