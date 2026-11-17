# Builds the bot-protection UPDATE package:
#   deployment/bot-protection-update.zip
# Only new + modified deployment files, at exact Laravel root-relative
# paths, no wrapper directory. Includes MANIFEST.md and DEPLOYMENT_GUIDE.md.
# Excludes .env, .git, vendor, node_modules.
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$out  = Join-Path $root "deployment\bot-protection-update.zip"

$files = @(
    'app/Console/Commands/AggregateAnalytics.php',
    'app/Http/Controllers/Admin/BotProtectionController.php',
    'app/Http/Middleware/BotGuard.php',
    'app/Providers/AppServiceProvider.php',
    'app/Support/Analytics.php',
    'app/Support/BotAnalytics.php',
    'app/Support/BotMetrics.php',
    'app/Support/CrawlerVerifier.php',
    'app/Support/ReverseDnsCrawlerVerifier.php',
    'app/Support/TrafficClassifier.php',
    'app/Support/TrafficVerdict.php',
    'bootstrap/app.php',
    'config/bot.php',
    'database/migrations/2026_11_17_150000_create_bot_protection_tables.php',
    'resources/views/admin/bot-protection/index.blade.php',
    'resources/views/layouts/admin.blade.php',
    'routes/web.php',
    '.env.example'
)

# Complete consistent public/build (the rebuilt app CSS replaced the old one)
$buildFiles = Get-ChildItem -Recurse -File (Join-Path $root 'public\build') | ForEach-Object {
    $rel = $_.FullName.Substring($root.Length).TrimStart('\', '/')
    $rel -replace '\\', '/'
}

foreach ($f in ($files + $buildFiles)) {
    if (-not (Test-Path (Join-Path $root $f))) { throw "Missing source file: $f" }
}

$manifest = @'
# Deployment Manifest — Bot Protection Update

- **Commit:** 6bd4864 — feat: add bot detection blocking and analytics
- **Package:** bot-protection-update.zip
- **Scope:** application-managed bot detection, blocking and analytics
  (no Cloudflare, no Redis, no SSH, no queue workers required).
- **Migration:** one new migration — run `migrate --force` through the
  secure Admin → Artisan Runner (no SSH needed). It only creates two new
  tables (`bot_traffic_metrics`, `bot_event_log`); no existing data is
  touched.
- **After upload:** run `optimize:clear` once through the Artisan Runner
  so the new `config/bot.php` is picked up if config was ever cached.

## Included files (Laravel root-relative, no wrapper directory)

Application:

```
app/Console/Commands/AggregateAnalytics.php      (modified: prunes bot tables)
app/Http/Controllers/Admin/BotProtectionController.php  (new: dashboard + settings)
app/Http/Middleware/BotGuard.php                 (new: global detection + enforcement)
app/Providers/AppServiceProvider.php             (modified: crawler verifier binding)
app/Support/Analytics.php                        (modified: classifier-aware tracking + tool-usage dedupe)
app/Support/BotAnalytics.php                     (new: dashboard queries)
app/Support/BotMetrics.php                       (new: atomic hourly counters)
app/Support/CrawlerVerifier.php                  (new: contract)
app/Support/ReverseDnsCrawlerVerifier.php        (new: forward-confirmed reverse DNS, cached)
app/Support/TrafficClassifier.php                (new: centralized classification)
app/Support/TrafficVerdict.php                   (new: classification value object)
bootstrap/app.php                                (modified: BotGuard registered globally)
config/bot.php                                   (new: defaults)
database/migrations/2026_11_17_150000_create_bot_protection_tables.php (new)
public/build/                                    (complete consistent rebuild; stale app-BbecGHJM.css removed)
resources/views/admin/bot-protection/index.blade.php (new: dashboard view)
resources/views/layouts/admin.blade.php          (modified: sidebar entry)
routes/web.php                                   (modified: bot-protection routes + search throttle)
.env.example                                     (reference only — do NOT upload over a live .env)
```

Documentation and tests (not required at runtime, safe to upload):

```
.docs/ANALYTICS.md        (modified: cross-reference)
.docs/BOT_PROTECTION.md   (new)
tests/Feature/BotProtectionDashboardTest.php  (new)
tests/Feature/BotProtectionTest.php           (new)
tests/Unit/TrafficClassifierTest.php          (new)
```

Excluded by design: `.env`, `.git`, `vendor/`, `node_modules/`.

## What it does

- Classifies traffic server-side (Human / Verified Search Bot / Known Bot /
  Suspicious / Unknown) with cached reverse-DNS verification of claimed
  search crawlers; a fake Googlebot UA is never trusted.
- Detects and counts automated requests; blocks protected POST endpoints
  for automated clients (403); rate-limits suspicious/bot page requests
  (429 with Retry-After); counts every 429 exactly once.
- Preserves: human analytics, existing SMTP limits (10/min + 50/day tests,
  3/hour + 10/day sends), contact form behaviour (honeypot still rejected by
  validation, now also counted).
- Admin → Insights → Bot Protection: KPIs, charts, observed categories,
  top targeted endpoints, recent aggregated security events, and admin
  controls (disable requires explicit confirmation).
- Hourly aggregate tables only — no raw IPs, no request bodies, no
  per-human-page-view writes. Retention pruned by the existing daily
  `analytics:aggregate --cleanup` schedule.

## Verification performed before packaging

- Full PHP test suite: 516 passed (1841 assertions), including 34 new tests.
- JavaScript tests (node --test): 63 passed.
- Laravel Pint: passed.
- Vite production build: succeeded (public/build shipped in this package).
- ZIP integrity + extraction round-trip verified locally.
'@

$guide = @'
# Deployment Guide — Bot Protection Update (cPanel shared hosting)

No Cloudflare requirement. No SSH requirement. No Redis requirement.
No persistent queue workers required.

## What this package contains

New + modified files at their exact Laravel root-relative paths, plus a
complete consistent `public/build/`. There is **no wrapper directory**:
extract the ZIP directly inside the Laravel application root so paths land
correctly.

## Step 1 — Upload

1. cPanel → File Manager → open the **Laravel application folder**
   (Layout 1: `stunningtouch-app`; Layout 2: the folder containing
   `artisan` inside `public_html`).
2. Upload `bot-protection-update.zip` there.
3. Right-click the ZIP → **Extract** (extract in place, overwrite files).
4. Delete the uploaded ZIP afterwards.

Layout check: the ZIP must create `config/bot.php` next to the existing
`config/app.php`. If you see `config/config/bot.php`, you extracted one
level too deep — move the contents up.

`public_html/public/build/` (webroot copy) must stay consistent with the
app folder's `public/build/`: if your document root is the app folder's
own `public/` (Layout 1), nothing else is needed. In Layout 2, also copy
the extracted `public/build/` files into `public_html/public/build/`.

## Step 2 — Run the migration (no SSH)

Admin → **Artisan Runner** (granted admins only) and run:

```
migrate --force
```

This creates exactly two new tables (`bot_traffic_metrics`,
`bot_event_log`). Existing tables and historical analytics are untouched.

Then clear optimized caches so the new config file is loaded:

```
optimize:clear
```

(If config was never cached, this is harmless.)

## Step 3 — Verify

1. Visit the site as a normal visitor — pages must work as before.
2. Admin → **Insights → Bot Protection** — the dashboard renders with
   zeroed KPIs until real automated traffic is recorded (no fake values).
3. Watch it fill as crawlers/bots hit the site.
4. Optional: from outside your network run
   `curl -A "Googlebot/2.1 (+http://www.google.com/bot.html)" https://yourdomain.com/`
   — the request should return 200 and appear on the dashboard as a
   suspicious/known client (an unverified claim from an unknown IP is
   never counted as a verified search bot).

## Step 4 — Configure (optional)

Admin → Bot Protection → **Protection settings**:

- Enable/disable protection (disabling requires explicit confirmation).
- Block automated POST requests (recommended: on).
- Automated page rate limit (default 60/min per IP).
- Suspicious rate limit (default 30/min per IP).

Do not put bot settings in `.env` for runtime changes — the admin UI is
authoritative (`config/bot.php` only sets initial defaults).

## Cron

No new cron job is required. Counters are written on-request with atomic
hourly upserts; old buckets are pruned by the existing daily
`analytics:aggregate --cleanup` schedule (03:15 via the existing
`/run-scheduler/{secret}` cron).

## Measurement scope (read this before interpreting numbers)

- The dashboard covers **application-level** traffic only — requests that
  reached Laravel. Requests blocked by `.htaccess`, ModSecurity, Apache or
  the hosting firewall die before PHP and **cannot be measured here**.
  Check cPanel → Raw Access Logs / ModSecurity for that layer. Nothing on
  the dashboard estimates those numbers.
- "Blocked Requests" = requests actually rejected by this application
  (403 / honeypot). "Rate-Limited Requests" = requests rejected because a
  configured rate limit was exceeded (429). "Allowed Bot Requests" =
  automated requests intentionally permitted (e.g. verified crawlers
  reading pages). A detected bot is not automatically a blocked bot.

## Rollback

Delete the uploaded files (list in MANIFEST.md), re-upload the previous
versions if kept, and run `optimize:clear`. The two new tables can remain
harmlessly; drop them with `migrate:rollback` if truly needed.
'@

foreach ($f in @('MANIFEST.md', 'DEPLOYMENT_GUIDE.md', '.env')) {
    if ($f -eq '.env' -and (Test-Path (Join-Path $root $f))) { continue }
}

if (Test-Path $out) { Remove-Item $out -Force }
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $out) | Out-Null

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$stream = [System.IO.File]::Open($out, [System.IO.FileMode]::CreateNew)
$archive = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($f in ($files + $buildFiles)) {
        $entry = $archive.CreateEntry($f, [System.IO.Compression.CompressionLevel]::Optimal)
        $entryStream = $entry.Open()
        $fileStream = [System.IO.File]::OpenRead((Join-Path $root $f))
        try { $fileStream.CopyTo($entryStream) } finally { $fileStream.Dispose(); $entryStream.Dispose() }
    }

    foreach ($doc in @(@('manifest', $manifest), @('guide', $guide))) {
        $name = if ($doc[0] -eq 'manifest') { 'MANIFEST.md' } else { 'DEPLOYMENT_GUIDE.md' }
        $entry = $archive.CreateEntry($name, [System.IO.Compression.CompressionLevel]::Optimal)
        $entryStream = $entry.Open()
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($doc[1])
        $entryStream.Write($bytes, 0, $bytes.Length)
        $entryStream.Dispose()
    }
} finally {
    $archive.Dispose()
    $stream.Dispose()
}

# ---- Integrity verification: round-trip extraction + hash comparison ----
$temp = Join-Path $env:TEMP "bot-protection-zip-verify"
if (Test-Path $temp) { Remove-Item $temp -Recurse -Force }
[System.IO.Compression.ZipFile]::ExtractToDirectory($out, $temp)

$zipEntries = [System.IO.Compression.ZipFile]::OpenRead($out)
$entryCount = $zipEntries.Entries.Count
$zipEntries.Dispose()

$mismatches = 0
foreach ($f in ($files + $buildFiles)) {
    $srcHash = (Get-FileHash (Join-Path $root $f) -Algorithm SHA256).Hash
    $extHash = (Get-FileHash (Join-Path $temp $f) -Algorithm SHA256).Hash
    if ($srcHash -ne $extHash) { Write-Host "MISMATCH: $f"; $mismatches++ }
}

$required = @('MANIFEST.md', 'DEPLOYMENT_GUIDE.md', 'config/bot.php', 'database/migrations/2026_11_17_150000_create_bot_protection_tables.php')
foreach ($r in $required) {
    if (-not (Test-Path (Join-Path $temp $r))) { throw "Missing in ZIP: $r" }
}

# Safety: forbidden content must not be present
$forbidden = @('.env;', 'vendor/', 'node_modules/', '.git/')
$listing = [System.IO.Compression.ZipFile]::OpenRead($out)
$names = $listing.Entries.FullName -join "`n"
$listing.Dispose()
foreach ($b in @('^.env$', '^vendor/', '^node_modules/', '^\.git')) {
    if (($names -split "`n") -match $b) { throw "Forbidden entry present: $b" }
}

Remove-Item $temp -Recurse -Force

if ($mismatches -gt 0) { throw "$mismatches file(s) did not survive the round-trip" }

$hash = (Get-FileHash -Algorithm SHA256 $out).Hash.ToLowerInvariant()
$size = '{0:N2}' -f ((Get-Item $out).Length / 1KB)
Write-Host "Done: $out ($size KB, $entryCount entries)"
Write-Host "Round-trip verified: $((($files + $buildFiles).Count + 2)) files match"
Write-Host "SHA-256: $hash"
