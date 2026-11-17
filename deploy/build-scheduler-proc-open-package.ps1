# Builds the SHARED-HOSTING SCHEDULER FIX package:
#   deployment/scheduler-proc-open-fix.zip
# Ships commit 6b86ba6 (fix: support in-process scheduler execution on
# shared hosting). Only new + modified deployment files, at exact Laravel
# root-relative paths, no wrapper directory. Includes MANIFEST.md and
# DEPLOYMENT_GUIDE.md. Excludes .env, .git, vendor, node_modules.
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$out  = Join-Path $root "deployment\scheduler-proc-open-fix.zip"

$files = @(
    'routes/console.php',
    'app/Support/ScheduledArtisan.php'
)

# Reference and tests (not required at runtime, safe to upload)
$docFiles = @(
    'tests/Feature/SchedulerInProcessTest.php',
    'tests/Unit/ScheduledArtisanTest.php'
)

# Complete consistent public/build (unchanged content, shipped for consistency)
$buildFiles = Get-ChildItem -Recurse -File (Join-Path $root 'public\build') | ForEach-Object {
    $rel = $_.FullName.Substring($root.Length).TrimStart('\', '/')
    $rel -replace '\\', '/'
}

foreach ($f in ($files + $docFiles + $buildFiles)) {
    if (-not (Test-Path (Join-Path $root $f))) { throw "Missing source file: $f" }
}

$manifest = @'
# Deployment Manifest — Shared-Hosting Scheduler Fix (proc_open)

- **Commit:** `6b86ba6` (fix: support in-process scheduler execution on shared hosting)
- **Package:** scheduler-proc-open-fix.zip
- **Scope:** all four scheduled tasks (`blog:auto-pipeline`, `blog:publish-scheduled`,
  `analytics:aggregate --cleanup`, `blog:cleanup-history`) now execute inside the
  current PHP process via `Schedule::call()` + `ScheduledArtisan::call()` instead of
  spawning `php artisan ...` subprocesses through Symfony Process. Fixes the production
  error "The Process class relies on proc_open, which is not available on your PHP
  installation." raised from `Illuminate\Console\Scheduling\Event` on every cron tick.
- **Migration:** none — the database is not touched.
- **Dependencies:** none added. No Redis, no queue worker, no new cron job, no new
  server service. The existing every-minute cPanel cron (`schedule:run`) is unchanged.
- **Behavior preserved:** original frequencies (`*/5 * * * *`, `* * * * *`,
  `15 3 * * *`, `45 4 * * 1`), app timezone, `withoutOverlapping()` and
  `onOneServer()` cache mutexes (same names/keys), maintenance-mode handling, and
  the `/run-scheduler/{secret}` HTTP trigger. Failures still surface as
  `ScheduledTaskFailed` events with the command output logged.

## Included files (Laravel root-relative, no wrapper directory)

Application:

```
routes/console.php                   (modified: 4x Schedule::command -> Schedule::call,
                                      each event named, frequencies and locks preserved)
app/Support/ScheduledArtisan.php     (new: in-process Artisan runner for the scheduler;
                                      captures output, logs + throws on non-zero exit)
```

Reference and tests (not required at runtime, safe to upload):

```
tests/Feature/SchedulerInProcessTest.php (new: 11 regression tests, including a real
                                          schedule:run executed with
                                          -d disable_functions=proc_open)
tests/Unit/ScheduledArtisanTest.php      (new: 3 unit tests for exit codes/exceptions)
```

Excluded by design: `.env`, `.git`, `vendor/`, `node_modules/`.

## Verification performed before packaging

- Full PHP test suite: 563 passed (2075 assertions), including the 14 new
  scheduler tests. The scheduler regression test runs the real
  `php -d disable_functions=proc_open artisan schedule:run` end-to-end and
  confirms the every-minute task executes with no proc_open dependency.
- JavaScript tests (node --test): 88 passed (no JS changes in this update).
- Laravel Pint: passed. Vite production build: succeeded.
- ZIP integrity + extraction round-trip verified locally (per-file hashes).

## Hosting-level manual attention (read the DEPLOYMENT_GUIDE.md)

1. Keep the existing cPanel cron untouched:
   `/opt/cpanel/ea-php83/root/usr/bin/php /home/stunning/public_html/artisan schedule:run >> /dev/null 2>&1`
2. After upload run `optimize:clear` so the cached console routes (if any)
   pick up the new scheduler registrations.
3. Tasks now run inside the cron PHP process; the auto-pipeline task remains
   bounded (one attempt per tick) — PHP `max_execution_time`/`memory_limit`
   for cron PHP apply as before.
'@

$guide = @'
# Deployment Guide — Shared-Hosting Scheduler Fix (cPanel shared hosting)

No SSH requirement. No new dependencies. No database migration. No new cron
job. The existing cron command stays exactly as it is:

```
/opt/cpanel/ea-php83/root/usr/bin/php /home/stunning/public_html/artisan schedule:run >> /dev/null 2>&1
```

## What this package changes

Laravel's scheduler used to execute every `Schedule::command()` task by
spawning a `php artisan ...` subprocess (Symfony Process), which requires
`proc_open()` — disabled on this host. Every cron tick therefore raised
"The Process class relies on proc_open, which is not available on your PHP
installation." and no scheduled task ran.

After this update all four scheduled tasks run inside the already-running
PHP process (`Schedule::call()` callbacks that execute the same Artisan
commands via `ScheduledArtisan::call()`):

| Task | Frequency | Notes |
|---|---|---|
| `blog:auto-pipeline` | every 5 minutes | unchanged arguments |
| `blog:publish-scheduled` | every minute | unchanged |
| `analytics:aggregate` | daily 03:15 | `--cleanup` preserved |
| `blog:cleanup-history` | weekly Monday 04:45 | default retention |

Overlap prevention (`withoutOverlapping`) and one-server locking
(`onOneServer`) keep using the same cache-based mutexes, so tasks cannot
run twice and cannot collide with the `/run-scheduler/{secret}` endpoint.

## Step 1 — Upload

1. cPanel -> File Manager -> open the **Laravel application folder**
   (the folder that contains `artisan`).
2. Upload `scheduler-proc-open-fix.zip` there.
3. Right-click the ZIP -> **Extract** (extract in place, overwrite files).
4. Delete the uploaded ZIP afterwards.

Layout check: the ZIP must update `routes/console.php` next to the existing
`routes/web.php` and create `app/Support/ScheduledArtisan.php` next to
`app/Support/ArtisanRunner.php`. If you see `app/app/Support/...`, you
extracted one level too deep — move the contents up.

## Step 2 — Clear caches (no SSH)

Admin -> **Artisan Runner** (granted admins only) and run:

```
optimize:clear
```

## Step 3 — Verify (important)

1. Artisan Runner -> run `schedule:list` (read-only). You should see the
   four tasks listed by name: `blog:auto-pipeline`, `blog:publish-scheduled`,
   `analytics:aggregate`, `blog:cleanup-history` — each marked as running in
   the foreground (`Next Due:` values depend on the current time).
2. Wait for the next cron tick (every minute), then check the Laravel log
   (`storage/logs/laravel.log`): there must be **no** "proc_open" entries and
   no `ScheduledTaskFailed` reports for the standard tasks. Scheduled
   publications are applied within a minute for due posts.
3. Optional immediate check without waiting for cron: open
   `https://stunningtouch.com/run-scheduler/{secret}` (the secret from
   `config('services.scheduler.secret')`, rate-limited to 2 requests/minute).
   A blank/`<p>...</p>` OK response means `schedule:run` completed in-process.
4. Scheduled posts: create a post scheduled a minute ahead; after the next
   cron tick it must become published.
5. Analytics: after 03:15 (or by running `analytics:aggregate` through the
   runner) confirm `analytics_daily` gains a row for yesterday and old raw
   rows are pruned when `--cleanup` applies.

## Rollback

Delete/restore the uploaded files listed in MANIFEST.md (previous versions
if kept), i.e. restore the previous `routes/console.php` and remove
`app/Support/ScheduledArtisan.php`, then run `optimize:clear`. No database
or cron changes are involved.
'@

if (Test-Path $out) { Remove-Item $out -Force }
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $out) | Out-Null

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$stream = [System.IO.File]::Open($out, [System.IO.Compression.ZipArchiveMode]::Create)
$archive = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($f in ($files + $docFiles + $buildFiles)) {
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
$temp = Join-Path $env:TEMP "scheduler-proc-open-zip-verify"
if (Test-Path $temp) { Remove-Item $temp -Recurse -Force }
[System.IO.Compression.ZipFile]::ExtractToDirectory($out, $temp)

$zipEntries = [System.IO.Compression.ZipFile]::OpenRead($out)
$entryCount = $zipEntries.Entries.Count
$zipEntries.Dispose()

$mismatches = 0
foreach ($f in ($files + $docFiles + $buildFiles)) {
    $srcHash = (Get-FileHash (Join-Path $root $f) -Algorithm SHA256).Hash
    $extHash = (Get-FileHash (Join-Path $temp $f) -Algorithm SHA256).Hash
    if ($srcHash -ne $extHash) { Write-Host "MISMATCH: $f"; $mismatches++ }
}

$required = @('MANIFEST.md', 'DEPLOYMENT_GUIDE.md', 'routes/console.php', 'app/Support/ScheduledArtisan.php')
foreach ($r in $required) {
    if (-not (Test-Path (Join-Path $temp $r))) { throw "Missing in ZIP: $r" }
}

# Safety: forbidden content must not be present
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
Write-Host "Round-trip verified: $((($files + $docFiles + $buildFiles).Count + 2)) files match"
Write-Host "SHA-256: $hash"
