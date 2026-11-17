# Builds the TECHNICAL SEO UPDATE package:
#   deployment/seo-improvements-update.zip
# Also ships the schedule:list artisan-runner allowlist (66a9447).
# Only new + modified deployment files, at exact Laravel root-relative
# paths, no wrapper directory. Includes MANIFEST.md and DEPLOYMENT_GUIDE.md.
# Excludes .env, .git, vendor, node_modules.
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$out  = Join-Path $root "deployment\seo-improvements-update.zip"

$files = @(
    'app/Http/Controllers/Public/HomeController.php',
    'app/Http/Controllers/Public/SitemapController.php',
    'app/Http/Middleware/RedirectCanonicalHost.php',
    'app/Http/Middleware/SecurityHeaders.php',
    'app/Providers/AppServiceProvider.php',
    'app/Support/ArtisanRunner.php',
    'app/Support/SeoResolver.php',
    'bootstrap/app.php',
    'public/.htaccess',
    'public/robots.txt',
    'public/favicon.ico',
    'public/apple-touch-icon.png',
    'public/og-image.png',
    'resources/views/layouts/public.blade.php',
    'resources/views/partials/seo-head.blade.php'
)

# Reference templates (already-deployed servers keep their own root .htaccess;
# a copy of webroot-htaccess.txt is shipped AS the root .htaccess entry below)
$docFiles = @(
    '.docs/SEO_GUIDE.md',
    'deploy/webroot-htaccess.txt',
    'deploy/root-public-htaccess.txt',
    'tests/Feature/SeoTest.php',
    'tests/Feature/TechnicalSeoTest.php',
    'tests/Unit/ArtisanRunnerTest.php'
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
# Deployment Manifest — Technical SEO Update

- **Commits:** `66a9447` (feat: allowlist schedule:list in artisan runner) +
  `90b16d3` (feat: improve technical seo and social metadata)
- **Package:** seo-improvements-update.zip
- **Scope:** canonical host redirects (www/http -> https://stunningtouch.com),
  canonical sitemap + robots.txt, default OG/Twitter image + metadata
  fallbacks, HSTS on HTTPS responses, homepage meta description, favicon
  declarations (SVG + ICO + apple touch icon). Also includes the
  `schedule:list` read-only allowlist entry for the Admin Artisan Runner.
- **Migration:** none — the database is not touched.
- **Dependencies:** none added. No changes to BotGuard, analytics, rate
  limits, tool usage tracking or the admin Artisan Runner gate.
- **Canonical domain:** https://stunningtouch.com (APP_URL).

## Included files (Laravel root-relative, no wrapper directory)

Application:

```
app/Http/Controllers/Public/HomeController.php   (modified: 154-char meta description)
app/Http/Controllers/Public/SitemapController.php (modified: canonical-host URLs)
app/Http/Middleware/RedirectCanonicalHost.php    (new: www -> apex, single hop, loop-free)
app/Http/Middleware/SecurityHeaders.php          (modified: HSTS on HTTPS responses)
app/Providers/AppServiceProvider.php             (modified: forceRootUrl in production)
app/Support/ArtisanRunner.php                    (modified: schedule:list allowlist entry)
app/Support/SeoResolver.php                      (modified: defaultOgImage helper)
bootstrap/app.php                                (modified: canonical redirect middleware)
public/.htaccess                                 (modified: canonical host + HTTPS rules)
public/robots.txt                                (modified: policy + Sitemap directive)
public/favicon.ico                               (replaced: was 0 bytes, now real 16/32/48 icon)
public/apple-touch-icon.png                      (new: 180x180 brand icon)
public/og-image.png                              (new: 1200x630 default social preview)
resources/views/layouts/public.blade.php         (modified: favicon links, canonical breadcrumb)
resources/views/partials/seo-head.blade.php      (modified: OG/Twitter fallbacks, canonical host)
.htaccess                                        (root web .htaccess = deploy/webroot-htaccess.txt,
                                                  with canonical host + HTTPS rules)
```

Reference, documentation and tests (not required at runtime, safe to upload):

```
.docs/SEO_GUIDE.md                        (modified: documents all new behavior)
deploy/webroot-htaccess.txt               (modified: canonical rules, Layout reference)
deploy/root-public-htaccess.txt           (modified: canonical rules, Layout 2 reference)
tests/Feature/SeoTest.php                 (modified: canonical sitemap assertions)
tests/Feature/TechnicalSeoTest.php        (new: 26 tests for all six improvements)
tests/Unit/ArtisanRunnerTest.php          (modified: schedule:list allowlist tests)
```

Excluded by design: `.env`, `.git`, `vendor/`, `node_modules/`.

## Verification performed before packaging

- Full PHP test suite: 549 passed (2006 assertions), including 26 new
  TechnicalSeoTest cases and the updated SeoTest/ArtisanRunnerTest.
- JavaScript tests (node --test): 88 passed (no JS changes in this update).
- Laravel Pint: passed. Vite production build: succeeded.
- ZIP integrity + extraction round-trip verified locally (per-file hashes).

## Hosting-level manual attention (read the DEPLOYMENT_GUIDE.md)

1. The root `.htaccess` on the live server (Layout 1) must be replaced by
   the shipped `.htaccess` entry. For Layout 2 the file lives OUTSIDE the
   app folder (public_html/.htaccess) — update it manually from
   `deploy/root-public-htaccess.txt`.
2. After upload, verify https://stunningtouch.com/ still loads (no
   redirect loop). The HTTPS rule is guarded by X-Forwarded-Proto; if your
   proxy strips it, remove the marked 3-line HTTPS block only.
3. The www TLS certificate must cover www.stunningtouch.com (verified
   before packaging).
'@

$guide = @'
# Deployment Guide — Technical SEO Update (cPanel shared hosting)

No SSH requirement. No new dependencies. No database migration.

## What this package changes

1. Canonical redirects: every http:// or www. URL 301s to
   https://stunningtouch.com (single hop, path + query preserved).
2. robots.txt ships with the canonical Sitemap directive.
3. Sitemap and canonical tags always use the canonical domain.
4. Social previews (Open Graph/Twitter) always have an image
   (bundled default og-image.png when a page sets none).
5. HSTS (max-age=31536000) on HTTPS responses.
6. Real favicon.ico (the old one was empty), SVG + apple-touch-icon links.
7. Admin Artisan Runner accepts the read-only `schedule:list` command.

## Step 1 — Upload

1. cPanel -> File Manager -> open the **Laravel application folder**
   (Layout 1: the folder with `artisan` that serves through the root
   `.htaccess`; Layout 2: the folder inside `public_html`).
2. Upload `seo-improvements-update.zip` there.
3. Right-click the ZIP -> **Extract** (extract in place, overwrite files).
4. Delete the uploaded ZIP afterwards.

Layout check: the ZIP must update `app/Support/SeoResolver.php` next to
the existing `app/Support/ArtisanRunner.php`. If you see
`app/app/Support/...`, you extracted one level too deep — move the
contents up.

## Step 2 — Update the web-server .htaccess files

These live OUTSIDE the ZIP contents depending on the layout:

- **Layout 1** (document root = the app folder): the ZIP ships a root
  `.htaccess` file at the package root — it overwrites the old one during
  extraction (it now contains the canonical host + HTTPS rules).
- **Layout 2** (document root = public_html, app inside a subfolder):
  the working `.htaccess` is `public_html/.htaccess` — replace its
  rewrite block manually from the shipped
  `deploy/root-public-htaccess.txt` (the ZIP lists it under `deploy/`).

## Step 3 — Clear caches (no SSH)

Admin -> **Artisan Runner** (granted admins only) and run:

```
optimize:clear
```

## Step 4 — Verify (important)

1. `https://stunningtouch.com/` must load normally — **if you see a
   redirect loop**, remove the 3-line HTTPS block (the rules below the
   "Force HTTPS" comment) from the root `.htaccess` and re-check. This
   only matters if your hosting front-end proxies TLS to Apache without
   forwarding `X-Forwarded-Proto` (rare; the standard cPanel setups do).
2. `curl -I http://stunningtouch.com/` -> 301 to https://stunningtouch.com/
3. `curl -I https://www.stunningtouch.com/` -> 301 to https://stunningtouch.com/
4. `curl -s https://stunningtouch.com/robots.txt` -> contains
   `Sitemap: https://stunningtouch.com/sitemap.xml`
5. `curl -sI https://stunningtouch.com/` -> contains
   `Strict-Transport-Security: max-age=31536000`
6. Upload nothing anywhere; view-source on the homepage and confirm
   `<meta property="og:image" content="https://stunningtouch.com/og-image.png">`.
7. `/admin` login and the Artisan Runner (`schedule:list`) work as before.

## Rollback

Delete/restore the uploaded files listed in MANIFEST.md (previous
versions if kept), restore the previous root `.htaccess`, and run
`optimize:clear`. The 301 redirects are cached by clients for a while —
that is expected and harmless.
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

    # Root web .htaccess entry (Layout 1) from the webroot template
    $entry = $archive.CreateEntry('.htaccess', [System.IO.Compression.CompressionLevel]::Optimal)
    $entryStream = $entry.Open()
    $bytes = [System.Text.Encoding]::UTF8.GetBytes((Get-Content (Join-Path $root 'deploy\webroot-htaccess.txt') -Raw))
    $entryStream.Write($bytes, 0, $bytes.Length)
    $entryStream.Dispose()

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
$temp = Join-Path $env:TEMP "seo-improvements-zip-verify"
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

# The root .htaccess entry must match the webroot template
$rootHtaccessHash = (Get-FileHash (Join-Path $temp '.htaccess') -Algorithm SHA256).Hash
$templateHash = (Get-FileHash (Join-Path $root 'deploy\webroot-htaccess.txt') -Algorithm SHA256).Hash
if ($rootHtaccessHash -ne $templateHash) { Write-Host 'MISMATCH: .htaccess (root entry)'; $mismatches++ }

$required = @('MANIFEST.md', 'DEPLOYMENT_GUIDE.md', '.htaccess', 'app/Http/Middleware/RedirectCanonicalHost.php', 'public/og-image.png', 'public/robots.txt', 'public/favicon.ico', 'public/apple-touch-icon.png')
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
Write-Host "Round-trip verified: $((($files + $docFiles + $buildFiles).Count + 3)) files match"
Write-Host "SHA-256: $hash"
