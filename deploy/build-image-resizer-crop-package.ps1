# Builds the image-resizer CROP UPDATE package:
#   deployment/image-resizer-crop-update.zip
# Only new + modified deployment files, at exact Laravel root-relative
# paths, no wrapper directory. Includes MANIFEST.md and DEPLOYMENT_GUIDE.md.
# Excludes .env, .git, vendor, node_modules.
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$out  = Join-Path $root "deployment\image-resizer-crop-update.zip"

$files = @(
    'resources/css/app.css',
    'resources/js/tools/crop-core.js',
    'resources/js/tools/crop-editor.js',
    'resources/js/tools/image-resizer.js',
    'resources/js/tools/image-tool.js',
    'resources/views/tools/image-resizer.blade.php'
)

# Documentation and tests (not required at runtime, safe to upload)
$docFiles = @(
    '.docs/TOOLS_CATALOG.md',
    'tests/Feature/ImageResizerCropTest.php',
    'tests/js/crop-core.test.mjs'
)

# Complete consistent public/build (rebuilt assets; stale hashed chunks gone)
$buildFiles = Get-ChildItem -Recurse -File (Join-Path $root 'public\build') | ForEach-Object {
    $rel = $_.FullName.Substring($root.Length).TrimStart('\', '/')
    $rel -replace '\\', '/'
}

foreach ($f in ($files + $docFiles + $buildFiles)) {
    if (-not (Test-Path (Join-Path $root $f))) { throw "Missing source file: $f" }
}

$manifest = @'
# Deployment Manifest — Image Resizer Crop Update

- **Commit:** c346e1b — feat: add interactive cropping to image resizer
- **Package:** image-resizer-crop-update.zip
- **Scope:** interactive crop editor integrated into the existing
  Image Resizer tool (client-side, no new dependencies, no new routes,
  no database changes, no Redis/queues/SSH).
- **Migration:** none — the database is not touched.
- **After upload:** run `optimize:clear` once through the Admin →
  Artisan Runner so rebuilt assets and views are picked up cleanly.
- **No library added:** Cropper.js was evaluated and intentionally not
  used — the crop editor is a purpose-built vanilla ES module
  (`crop-core.js` pure geometry + `crop-editor.js` DOM layer), matching
  the project's dependency-free tool architecture.

## Included files (Laravel root-relative, no wrapper directory)

Application:

```
resources/css/app.css                       (modified: crop editor styles + dark mode)
resources/js/tools/crop-core.js             (new: pure crop geometry, unit-tested)
resources/js/tools/crop-editor.js           (new: interactive editor, pointer/keyboard/touch)
resources/js/tools/image-resizer.js         (modified: editor integration + two-way sync)
resources/js/tools/image-tool.js            (modified: cropTo() crop+resize canvas helper)
resources/views/tools/image-resizer.blade.php (modified: crop UI, position presets, reset, preview)
public/build/                               (complete consistent rebuild)
```

Documentation and tests (not required at runtime, safe to upload):

```
.docs/TOOLS_CATALOG.md                      (modified: resizer feature notes)
tests/Feature/ImageResizerCropTest.php      (new: page markup + registry regression)
tests/js/crop-core.test.mjs                 (new: 25 node --test unit tests)
```

Excluded by design: `.env`, `.git`, `vendor/`, `node_modules/`.

## Behavior notes

- The width/height inputs remain the source of truth and drive the crop
  box (clamped inside the image). Dragging/resizing the box writes its
  dimensions back to the inputs (1:1 export). Requested sizes larger
  than the image are honored by upscaling the selected region.
- Keep Aspect Ratio captures the current crop ratio when enabled.
- Position presets (9) reposition without resizing; manual moves show a
  custom state (no preset highlighted).
- Processing stays 100% local in the browser; uploads, validation
  (15 MB, JPG/PNG/WebP), formats/quality, rate limits, analytics and
  the `image-resizer` component key are unchanged.

## Verification performed before packaging

- Full PHP test suite: 522 passed (1890 assertions), including the new
  ImageResizerCropTest regression tests.
- JavaScript tests (node --test): 88 passed (63 existing + 25 new
  crop-core tests).
- Laravel Pint: passed.
- Vite production build: succeeded (public/build shipped in this package).
- ZIP integrity + extraction round-trip verified locally (hash per file).
'@

$guide = @'
# Deployment Guide — Image Resizer Crop Update (cPanel shared hosting)

No SSH requirement. No Redis requirement. No queue workers. No new
dependencies. No database migration.

## What this package contains

New + modified files at their exact Laravel root-relative paths, plus a
complete consistent `public/build/`. There is **no wrapper directory**:
extract the ZIP directly inside the Laravel application root so paths
land correctly.

## Step 1 — Upload

1. cPanel → File Manager → open the **Laravel application folder**
   (Layout 1: `stunningtouch-app`; Layout 2: the folder containing
   `artisan` inside `public_html`).
2. Upload `image-resizer-crop-update.zip` there.
3. Right-click the ZIP → **Extract** (extract in place, overwrite files).
4. Delete the uploaded ZIP afterwards.

Layout check: the ZIP must update `resources/js/tools/image-resizer.js`
next to the existing `resources/js/tools/crop-core.js`. If you see
`resources/resources/...`, you extracted one level too deep — move the
contents up.

`public_html/public/build/` (webroot copy) must stay consistent with the
app folder's `public/build/`: if your document root is the app folder's
own `public/` (Layout 1), nothing else is needed. In Layout 2, also copy
the extracted `public/build/` files into `public_html/public/build/`
(including removing stale hashed assets replaced by this build).

## Step 2 — Clear caches (no SSH)

Admin → **Artisan Runner** (granted admins only) and run:

```
optimize:clear
```

This is enough: no config, route or database changes shipped.

## Step 3 — Verify

1. Open https://yourdomain.com/image-resizer as a visitor.
2. Upload any image — the crop editor appears with a draggable box,
   8 resize handles, position preset grid (9 buttons), a live crop
   preview and a "Reset crop" button.
3. Drag the box and handles: the width/height inputs follow the box and
   vice versa. "Keep aspect ratio" preserves the current ratio.
4. Press "Crop & Resize" and confirm the download matches the selected
   area at the requested size.
5. Other tools (compressor, converters, PDF tools) must behave exactly
   as before — this update does not touch them.

## Rollback

Delete the uploaded files listed in MANIFEST.md and re-upload the
previous versions if kept, then run `optimize:clear`. The previous
public/build/ asset set can be restored from the prior deployment.
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
$temp = Join-Path $env:TEMP "image-resizer-crop-zip-verify"
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

$required = @('MANIFEST.md', 'DEPLOYMENT_GUIDE.md', 'resources/js/tools/crop-core.js', 'resources/js/tools/crop-editor.js', 'resources/views/tools/image-resizer.blade.php')
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
