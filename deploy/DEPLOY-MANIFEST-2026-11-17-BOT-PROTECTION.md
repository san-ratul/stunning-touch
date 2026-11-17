# Deployment Manifest — Bot Protection Update (2026-11-17)

- **Commit:** `6bd4864` — `feat: add bot detection blocking and analytics`
- **Package:** `deployment/bot-protection-update.zip` (406.44 KB, 48 entries)
- **SHA-256:** `7eb9eba60ebda55a5bf18f8ac530f1011f6ad36b87663bd3ecf6795d78ddd110`
- **Builder:** `deploy/build-bot-protection-package.ps1` (rebuildable)
- **Migration:** one migration (`2026_11_17_150000_create_bot_protection_tables.php`) —
  creates `bot_traffic_metrics` + `bot_event_log` only. Run via the secure
  Admin → Artisan Runner (`migrate --force`), then `optimize:clear`.
- **Cron:** none required (existing `/run-scheduler/{secret}` schedule handles
  daily pruning through `analytics:aggregate --cleanup`).
- **Package contents:** root-relative paths, no wrapper directory; includes
  complete consistent `public/build/`, `MANIFEST.md`, `DEPLOYMENT_GUIDE.md`.
  Excludes `.env`, `.git`, `vendor/`, `node_modules/`.
- **Verification:** full test suite 516 passed (1841 assertions), JS tests
  63 passed, Laravel Pint passed, Vite build succeeded, ZIP round-trip
  hash-verified.

## Key facts for the live site

- Detection ≠ blocking: every counter measures what actually happened.
- Human page views produce zero bot-protection writes; only automated /
  rejected requests write one atomic hourly upsert.
- Existing SMTP limits preserved (10/min + 50/day tests; 3/hour + 10/day sends).
- Server-level (Apache/ModSecurity/.htaccess) rejections before PHP are not
  measurable from Laravel and are documented as such in the dashboard.
