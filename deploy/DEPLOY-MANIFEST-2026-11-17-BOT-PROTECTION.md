# Deployment Manifest — Bot Protection Update (2026-11-17)

- **Commits:** `6bd4864` (bot protection) + `edc670f` (runner allowlist) +
  migration idempotency & runner diagnostics
- **Package:** `deployment/bot-protection-update.zip` (412.71 KB, 50 entries)
- **SHA-256:** `22b230c5f10ebd5bad4aa06a54212c3add1a1e411d38500d779de514f8b7c1ef`
- **Builder:** `deploy/build-bot-protection-package.ps1` (rebuildable)
- **Migration:** run `migrate --force` through the secure Admin → Artisan
  Runner (`migrate` and `migrate:status` are allowlisted). Both shipped
  migrations are idempotent: `2026_11_17_000001_add_artisan_runner_access_to_users_table`
  skips safely when the `artisan_runner_access` column was already added by
  a manual SQL command (as happened on the live database — the column
  exists without a migrations-log record), and the bot-protection migration
  skips tables that already exist. A stale migrations-log state can no
  longer block `migrate --force`.
- **Runner diagnostics:** database failures now surface a readable,
  SQL-stripped error (`SQLSTATE[42S21]: Column already exists …`) instead
  of a generic message; the full detail stays in the log.
- **Cron:** none required (existing `/run-scheduler/{secret}` schedule handles
  daily pruning through `analytics:aggregate --cleanup`).
- **Package contents:** root-relative paths, no wrapper directory; includes
  complete consistent `public/build/`, `MANIFEST.md`, `DEPLOYMENT_GUIDE.md`.
  Excludes `.env`, `.git`, `vendor/`, `node_modules/`.
- **Verification:** full test suite 518 passed (1856 assertions), JS tests
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
