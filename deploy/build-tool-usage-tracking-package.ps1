# Builds the TOOL USAGE TRACKING UPDATE package:
#   deployment/tool-usage-tracking-update.zip
# Ships commit e0e66c3 (fix: record tool usage beacons for all client-side
# tools). Ships the complete consistent public/build (hashed asset names
# change) + source reference files, at exact Laravel root-relative paths,
# no wrapper directory. Includes MANIFEST.md and DEPLOYMENT_GUIDE.md.
# Excludes .env, .git, vendor, node_modules.
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$out  = Join-Path $root "deployment\tool-usage-tracking-update.zip"

# Reference sources (not required at runtime; the runtime assets are public/build)
$srcFiles = @(
    'resources/js/tools/base64.js',
    'resources/js/tools/case-converter.js',
    'resources/js/tools/json-formatter.js',
    'resources/js/tools/qr-code-generator.js',
    'resources/js/tools/regex-tester.js',
    'resources/js/tools/strong-password-generator.js',
    'resources/js/tools/uuid-generator.js',
    'resources/js/tools/word-counter.js'
)

# Reference and tests (not required at runtime, safe to upload)
$docFiles = @(
    'tests/Unit/ToolUsageTrackingTest.php'
)

# Complete consistent public/build (required: hashed asset names changed)
$buildFiles = Get-ChildItem -Recurse -File (Join-Path $root 'public\build') | ForEach-Object {
    $rel = $_.FullName.Substring($root.Length).TrimStart('\', '/')
    $rel -replace '\\', '/'
}

foreach ($f in ($srcFiles + $docFiles + $buildFiles)) {
    if (-not (Test-Path (Join-Path $root $f))) { throw "Missing source file: $f" }
}

$manifest = @'
# Deployment Manifest — Tool Usage Tracking Update

- **Commit:** `e0e66c3` (fix: record tool usage beacons for all client-side tools)
- **Package:** tool-usage-tracking-update.zip
- **Scope:** all 14 client-side tools now fire the /api/tool-usage beacon when they
  actually process something. Previously only the image tools (compressor, resizer,
  jpg-to-png, png-to-webp), the PDF tools (merge, split) and the SMTP tester recorded
  usage, so /admin/tool-statistics and the admin dashboard showed no (or near-zero)
  data for everything else — e.g. generating a UUID or a password never recorded
  anything. Now covered: word-counter, case-converter, json-formatter,
  base64-encoder-decoder, qr-code-generator, uuid-generator, regex-tester and
  strong-password-generator (plus the already-tracking tools, rebuilt with the same
  Vite bundle). Failures (invalid Base64, invalid JSON, catastrophic regex patterns,
  empty password groups, QR generation errors) are recorded as failed runs; QR/PDF/
  image downloads count as 'download' events.
- **Migration:** none — the database is not touched.
- **Dependencies:** none added. Existing server-side guards unchanged: bot traffic is
  never recorded and identical submissions within the dedupe window are not
  double-counted.
- **Runtime change:** `public/build/**` (hashed asset names change) + updated
  `manifest.json`.

## Included files (Laravel root-relative, no wrapper directory)

Runtime (required):

```
public/build/**                    (complete, consistent build; manifest.json updated)
```

Reference sources and tests (not required at runtime, safe to upload):

```
resources/js/tools/base64.js                    (modified: encode/decode beacons)
resources/js/tools/case-converter.js            (modified: transform beacons)
resources/js/tools/json-formatter.js            (modified: format/minify beacons)
resources/js/tools/qr-code-generator.js         (modified: generate + download beacons)
resources/js/tools/regex-tester.js              (modified: run/error beacons)
resources/js/tools/strong-password-generator.js (modified: generate beacons)
resources/js/tools/uuid-generator.js            (modified: generate beacon)
resources/js/tools/word-counter.js              (modified: analyze beacon)
tests/Unit/ToolUsageTrackingTest.php            (new: fails when a client-side tool
                                                 never fires a usage beacon)
```

Excluded by design: `.env`, `.git`, `vendor/`, `node_modules/`.

## Verification performed before packaging

- Full PHP test suite: 577 passed (2089 assertions), including the 14 new
  ToolUsageTrackingTest cases.
- JavaScript tests (node --test): 88 passed (no JS core changes in this update).
- Laravel Pint: passed. Vite production build: succeeded.
- ZIP integrity + extraction round-trip verified locally (per-file hashes).
'@

$guide = @'
# Deployment Guide — Tool Usage Tracking Update (cPanel shared hosting)

No SSH requirement. No new dependencies. No database migration. No cron
changes.

## What this package changes

The admin Tool Statistics page (/admin/tool-statistics) counts rows in the
`tool_usage` table. Until now, most tools never reported their usage to
/api/tool-usage, so the page stayed empty even after visitors used the
tools (for example the UUID generator). This package adds the reporting
beacon to every client-side tool and ships the rebuilt `public/build`
assets that actually run on the server.

## Step 1 — Upload

1. cPanel -> File Manager -> open the **Laravel application folder**
   (the folder that contains `artisan`).
2. Upload `tool-usage-tracking-update.zip` there.
3. Right-click the ZIP -> **Extract** (extract in place, overwrite files).
4. Delete the uploaded ZIP afterwards.

Layout check: the ZIP must update `public/build/manifest.json` and add
`public/build/assets/uuid-generator-*.js` etc. If you see
`app/app/public/...`, you extracted one level too deep — move the contents up.

## Step 2 — Clear caches (no SSH)

Admin -> **Artisan Runner** (granted admins only) and run:

```
optimize:clear
```

(View caching is harmless here, but a clear guarantees the rebuilt asset
manifest is served.)

## Step 3 — Verify (important)

1. Hard-refresh a tool page (Ctrl+F5) so the browser drops the old cached
   JS bundle.
2. Use a tool for real: generate a UUID (or a password, or encode Base64...).
3. Open Admin -> Tool Statistics: the tool must now show a total (give it a
   second; the beacon is a tiny background POST). Same tool + same outcome
   within one minute is deduped by design, so a single use shows one run.
4. Bot protection: automated clients are never recorded; that behaviour is
   unchanged.

## Step 4 — Housekeeping (optional)

Old hashed assets (e.g. `public/build/assets/uuid-generator-DB2Kwfof.js`)
are no longer referenced after this update. They are harmless; you may
delete the stale files under `public/build/assets/` that are not listed in
`public/build/manifest.json` to save space.

## Rollback

Restore the previous `public/build/` directory (from a backup or the
previous deployment package) and run `optimize:clear`. No database or cron
changes are involved.
'@

if (Test-Path $out) { Remove-Item $out -Force }
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $out) | Out-Null

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$stream = [System.IO.File]::Open($out, [System.IO.Compression.ZipArchiveMode]::Create)
$archive = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($f in ($srcFiles + $docFiles + $buildFiles)) {
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
$temp = Join-Path $env:TEMP "tool-usage-tracking-zip-verify"
if (Test-Path $temp) { Remove-Item $temp -Recurse -Force }
[System.IO.Compression.ZipFile]::ExtractToDirectory($out, $temp)

$zipEntries = [System.IO.Compression.ZipFile]::OpenRead($out)
$entryCount = $zipEntries.Entries.Count
$zipEntries.Dispose()

$mismatches = 0
foreach ($f in ($srcFiles + $docFiles + $buildFiles)) {
    $srcHash = (Get-FileHash (Join-Path $root $f) -Algorithm SHA256).Hash
    $extHash = (Get-FileHash (Join-Path $temp $f) -Algorithm SHA256).Hash
    if ($srcHash -ne $extHash) { Write-Host "MISMATCH: $f"; $mismatches++ }
}

$required = @('MANIFEST.md', 'DEPLOYMENT_GUIDE.md', 'public/build/manifest.json', 'resources/js/tools/uuid-generator.js')
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
Write-Host "Round-trip verified: $((($srcFiles + $docFiles + $buildFiles).Count + 2)) files match"
Write-Host "SHA-256: $hash"
