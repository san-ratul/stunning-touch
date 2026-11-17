# Builds a minimal UPDATE package: only the files changed since the
# deployed release (b009202), at their correct document-root paths.
# Usage: powershell -ExecutionPolicy Bypass -File deploy\build-update-package.ps1
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$out  = Join-Path $PSScriptRoot "stunningtouch-update.zip"

# (source path in the repo) -> (path inside the package / on the server)
$files = @(
    @('deploy/webroot-htaccess.txt', '.htaccess'),
    @('public/build/manifest.json', 'public/build/manifest.json'),
    @('public/build/assets/app-DjfGAUH4.js', 'public/build/assets/app-DjfGAUH4.js'),
    @('public/build/assets/image-compressor-Cknro3Rm.js', 'public/build/assets/image-compressor-Cknro3Rm.js'),
    @('public/build/assets/image-resizer-DcUSrBQK.js', 'public/build/assets/image-resizer-DcUSrBQK.js'),
    @('public/build/assets/image-tool-DlrVtoUZ.js', 'public/build/assets/image-tool-DlrVtoUZ.js'),
    @('public/build/assets/jpg-to-png-Cro_FRQh.js', 'public/build/assets/jpg-to-png-Cro_FRQh.js'),
    @('public/build/assets/merge-pdf-DwEkMN9I.js', 'public/build/assets/merge-pdf-DwEkMN9I.js'),
    @('public/build/assets/png-to-webp-CuGdSLcG.js', 'public/build/assets/png-to-webp-CuGdSLcG.js'),
    @('public/build/assets/split-pdf-CUHQ7FT_.js', 'public/build/assets/split-pdf-CUHQ7FT_.js'),
    @('resources/views/admin/analytics/index.blade.php', 'resources/views/admin/analytics/index.blade.php')
)

foreach ($f in $files) { if (-not (Test-Path (Join-Path $root $f[0]))) { throw "Missing source file: $($f[0])" } }

if (Test-Path $out) { Remove-Item $out -Force }

Add-Type -AssemblyName System.IO.Compression
$stream = [System.IO.File]::Open($out, [System.IO.FileMode]::CreateNew)
$archive = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($f in $files) {
        $entryName = ($f[1] -replace '\\', '/')
        $entry = $archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
        $entryStream = $entry.Open()
        $fileStream = [System.IO.File]::OpenRead((Join-Path $root $f[0]))
        try { $fileStream.CopyTo($entryStream) } finally { $fileStream.Dispose(); $entryStream.Dispose() }
    }

    # Upload notes
    $notes = @'
STUNNING TOUCH - UPDATE PACKAGE (partial)

Fixes included:
1. Analytics country hint: browser TIMEZONE (Asia/Dhaka -> Bangladesh)
   instead of browser language (which mislabelled most visitors as US).
2. Root .htaccess: fixes the "/" -> /public redirect loop / 500.

Files (upload/overwrite via cPanel File Manager, keep exact paths):
  .htaccess                                          -> public_html/.htaccess
  public/build/manifest.json                         -> public_html/public/build/manifest.json
  public/build/assets/app-DjfGAUH4.js                -> public_html/public/build/assets/
  public/build/assets/image-compressor-Cknro3Rm.js   -> public_html/public/build/assets/
  public/build/assets/image-resizer-DcUSrBQK.js      -> public_html/public/build/assets/
  public/build/assets/image-tool-DlrVtoUZ.js         -> public_html/public/build/assets/
  public/build/assets/jpg-to-png-Cro_FRQh.js         -> public_html/public/build/assets/
  public/build/assets/merge-pdf-DwEkMN9I.js          -> public_html/public/build/assets/
  public/build/assets/png-to-webp-CuGdSLcG.js        -> public_html/public/build/assets/
  public/build/assets/split-pdf-CUHQ7FT_.js          -> public_html/public/build/assets/
  resources/views/admin/analytics/index.blade.php    -> public_html/resources/views/admin/analytics/
                                                       (extract the ZIP inside public_html so
                                                        relative paths land correctly)

Recommended cleanup AFTER uploading (optional; harmless to skip):
  delete the replaced stale assets in public_html/public/build/assets/:
    app-4GXTIFjB.js, image-compressor-1VZM88XR.js, image-resizer-PS20-QfX.js,
    image-tool-B8n7rB2j.js, jpg-to-png-PU7iBIXN.js, merge-pdf-BDdsaUQH.js,
    png-to-webp-BPmulm7Z.js, split-pdf-txRU3-ZA.js
  (the new manifest.json no longer references them)

Verify afterwards:
  https://yourdomain.com/                     -> homepage (200, no /public redirect)
  https://yourdomain.com/merge-pdf            -> tool works
  https://yourdomain.com/build/manifest.json  -> new manifest (references app-DjfGAUH4.js)
  Admin -> Visitor Analytics: your own visits should show Bangladesh
  (Tip: browser caches old 301 redirects - test in a private window or clear cache.)
'@
    $entry = $archive.CreateEntry('UPDATE-NOTES.txt', [System.IO.Compression.CompressionLevel]::Optimal)
    $entryStream = $entry.Open()
    $noteBytes = [System.Text.Encoding]::UTF8.GetBytes($notes)
    $entryStream.Write($noteBytes, 0, $noteBytes.Length)
    $entryStream.Dispose()
} finally {
    $archive.Dispose()
    $stream.Dispose()
}

$hash = (Get-FileHash -Algorithm SHA256 $out).Hash.ToLowerInvariant()
Write-Host "Done: $out ($('{0:N2}' -f ((Get-Item $out).Length / 1KB)) KB)"
Write-Host "SHA-256: $hash"
