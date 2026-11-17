# Deployment Manifest — Bot Protection Update (2026-11-17)

- **Commits:** `6bd4864` (bot protection) + `edc670f` (runner allowlist: `migrate`, `migrate:status`)
- **Package:** `deployment/bot-protection-update.zip` (411.70 KB, 49 entries)
- **SHA-256:** `ffd124b413047d5109aa93d4a0eae5af20ea82479bd4dcb4727e811711c5c2fc`
- **Builder:** `deploy/build-bot-protection-package.ps1` (rebuildable)
- **Migration:** one migration (`2026_11_17_150000_create_bot_protection_tables.php`) —
  creates `bot_traffic_metrics` + `bot_event_log` only. Run via the secure
  Admin → Artisan Runner (`migrate --force` — now an allowlisted command),
  then `optimize:clear`.
- **Cron:** none required (existing `/run-scheduler/{secret}` schedule handles
  daily pruning through `analytics:aggregate --cleanup`).
- **Package contents:** root-relative paths, no wrapper directory; includes
  complete consistent `public/build/`, `MANIFEST.md`, `DEPLOYMENT_GUIDE.md`.
  Excludes `.env`, `.git`, `vendor/`, `node_modules/`.
- **Verification:** full test suite 517 passed (1852 assertions), JS tests
  63 passed, Laravel Pint passed, Vite build succeeded, ZIP round-trip
  hash-verified.

## Key facts for the live site

- Detection ≠ blocking: every counter measures what actually happened.
- Human page views produce zero bot-protection writes; only automated /
  rejected requests write one atomic hourly upsert.
- Existing SMTP limits preserved (10/min + 50/day tests; 3/hour + 10/day sends).
- Server-level (Apache/ModSecurity/.htaccess) rejections before PHP are not
  measurable from Laravel and are documented as such in the dashboard.

## Security notes on the new allowlist entries

- `migrate` accepts only the `--force` flag — no `--step`, `--path`,
  `--pretend` or arguments. Dangerous variants (`migrate:fresh`,
  `migrate:rollback`, `migrate:reset`, `db:wipe`) remain rejected and are
  covered by regression tests.
- `migrate:status` is read-only (no options).
