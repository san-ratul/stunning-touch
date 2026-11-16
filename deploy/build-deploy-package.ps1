# Stunning Touch — deployment package builder
#
# Produces deploy/stunningtouch-deploy.zip — a complete, production-ready
# cPanel package (Laravel + production vendor/ + compiled assets + browser
# installer) that installs without SSH, Composer or Node.js on the server.
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File deploy\build-deploy-package.ps1
#
# Prerequisites (local dev machine):
#   - PHP CLI 8.3+ and Composer on PATH
#   - Frontend assets built:  npm ci && npm run build
#
# Outputs:
#   deploy/stunningtouch-deploy.zip           the package
#   deploy/stunningtouch-deploy.zip.sha256    checksum file
#   deploy/INSTALL-KEY.local.txt              installer authorization key (KEEP LOCAL)
#   deploy/package/                           staging directory (regenerated each run)
#
# Security: the package never contains .env, real credentials, git history,
# node_modules, local databases, logs or generated media.

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$pkg  = Join-Path $PSScriptRoot "package"
$zip  = Join-Path $PSScriptRoot "stunningtouch-deploy.zip"
$zipSha = "$zip.sha256"
$keyLocal = Join-Path $PSScriptRoot "INSTALL-KEY.local.txt"

Write-Host "== Stunning Touch deployment package builder ==" -ForegroundColor Cyan

function Fail($message) { throw $message }
function Check($ok, $message) { if (-not $ok) { Fail $message } }

# ---------------------------------------------------------------------------
# 1. Local prerequisites
# ---------------------------------------------------------------------------
Write-Host "-> checking prerequisites" -ForegroundColor Gray

$phpCmd = Get-Command php -ErrorAction SilentlyContinue
Check $phpCmd "PHP CLI not found on PATH. Install PHP 8.3+ locally."
$phpVersion = (& php -r "echo PHP_VERSION;")
Check ([version]::new($phpVersion.Split('-')[0]) -ge [version]'8.3.0') "Local PHP is $phpVersion — 8.3+ required."
Check (Get-Command composer -ErrorAction SilentlyContinue) "Composer not found on PATH. Install Composer locally."

$requiredExtensions = @('pdo_mysql', 'mbstring', 'openssl', 'ctype', 'json', 'fileinfo', 'tokenizer', 'curl', 'filter', 'hash', 'dom')
$loaded = (& php -m).ToLower() | ForEach-Object { $_.Trim() }
foreach ($ext in $requiredExtensions) {
    Check ($loaded -contains $ext) "Local PHP is missing the '$ext' extension."
}

Check (Test-Path (Join-Path $root "public\build\manifest.json")) "public/build/manifest.json not found. Run 'npm ci' and 'npm run build' first — refusing to package an incomplete frontend."
foreach ($f in @('artisan', 'composer.json', 'composer.lock', 'bootstrap\app.php', 'public\index.php', 'routes\web.php', 'deploy\installer-src\install.php', 'deploy\.env.production.template', 'deploy\INSTALL.txt', 'deploy\root-public-htaccess.txt')) {
    Check (Test-Path (Join-Path $root $f)) "Required file missing: $f"
}

# Deployment artifact hygiene: the working tree should not contain unexpected
# artifacts that could leak into the package.
$gitStatus = (& git status --porcelain 2>$null)
if ($LASTEXITCODE -eq 0 -and $gitStatus) {
    Write-Host "   note: git working tree has uncommitted changes (informational only)." -ForegroundColor Yellow
}

Write-Host "   PHP $phpVersion, Composer OK, frontend build OK" -ForegroundColor Green

# ---------------------------------------------------------------------------
# 2. Production composer dependencies
# ---------------------------------------------------------------------------
Write-Host "-> composer install --no-dev --prefer-dist --optimize-autoloader" -ForegroundColor Gray
Push-Location $root
try {
    & composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet 2>&1 | Out-Null
    Check ($LASTEXITCODE -eq 0) "composer install (production) failed."
} finally { Pop-Location }
Check (Test-Path (Join-Path $root "vendor\autoload.php")) "vendor/autoload.php missing after composer install."

# ---------------------------------------------------------------------------
# 3. Clean staging
# ---------------------------------------------------------------------------
if (Test-Path $pkg)  { Remove-Item $pkg -Recurse -Force }
if (Test-Path $zip)  { Remove-Item $zip -Force }
if (Test-Path $zipSha) { Remove-Item $zipSha -Force }
New-Item -ItemType Directory -Path $pkg | Out-Null

$appDir  = Join-Path $pkg "stunningtouch-app"
$htmlDir = Join-Path $pkg "public_html"
$insDir  = Join-Path $htmlDir "installer"   # temporary web-accessible installer
New-Item -ItemType Directory -Path $appDir, $htmlDir, $insDir | Out-Null

# ---------------------------------------------------------------------------
# 4. Application files (Laravel source lives OUTSIDE the webroot)
# ---------------------------------------------------------------------------
Write-Host "-> copying application files" -ForegroundColor Gray

# Robocopy: fast recursive copy with explicit exclusions.
$excludeDirs = @('.git', 'node_modules', 'tests', '.docs', 'docs', 'deploy')
$excludeFiles = @('.env', '.env.backup', '.env.production', 'database.sqlite', 'composer.phar', '.phpunit.result.cache')
$dirs = @('app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'vendor')
foreach ($d in $dirs) {
    if (Test-Path (Join-Path $root $d)) {
        robocopy (Join-Path $root $d) (Join-Path $appDir $d) /E /NFL /NDL /NJH /NJS /NP `
            /XD $excludeDirs | Out-Null
        Check ($LASTEXITCODE -lt 8) "robocopy failed for directory $d (exit $LASTEXITCODE)."
    }
}
foreach ($f in @('artisan', 'composer.json', 'composer.lock')) {
    Copy-Item (Join-Path $root $f) (Join-Path $appDir $f) -Force
}
Copy-Item (Join-Path $PSScriptRoot '.env.production.template') (Join-Path $appDir '.env.production.template') -Force

# Remove files that must never ship, even if they slipped into copied dirs.
$remove = @(
    (Join-Path $appDir 'database\database.sqlite'),
    (Join-Path $appDir 'database\database.sqlite-journal'),
    (Join-Path $appDir 'public\storage')            # local symlink, recreated on server
)
foreach ($f in $remove) {
    if (Test-Path $f) { Remove-Item $f -Recurse -Force }
}
# Storage: ship a clean skeleton with placeholders only (no local logs,
# sessions, caches, or generated media).
if (Test-Path (Join-Path $appDir 'storage')) { Remove-Item (Join-Path $appDir 'storage') -Recurse -Force }
New-Item -ItemType Directory -Force -Path `
    (Join-Path $appDir 'storage\app\public\media\covers'), `
    (Join-Path $appDir 'storage\framework\cache\data'), `
    (Join-Path $appDir 'storage\framework\sessions'), `
    (Join-Path $appDir 'storage\framework\views'), `
    (Join-Path $appDir 'storage\logs') | Out-Null
foreach ($d in @('storage', 'storage\app', 'storage\app\public', 'storage\app\public\media', 'storage\app\public\media\covers', 'storage\framework', 'storage\framework\cache', 'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs', 'bootstrap\cache')) {
    $dir = Join-Path $appDir $d
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    if ($d -ne 'bootstrap\cache') { Set-Content -LiteralPath (Join-Path $dir '.gitignore') -Value "*`n!.gitignore`n" -NoNewline:$false }
}
Set-Content -LiteralPath (Join-Path $appDir 'bootstrap\cache\.gitignore') -Value "*`n!.gitignore`n" -NoNewline:$false

# ---------------------------------------------------------------------------
# 5. public_html (document root)
# ---------------------------------------------------------------------------
Write-Host "-> preparing public_html" -ForegroundColor Gray

Copy-Item (Join-Path $appDir 'public\*') $htmlDir -Recurse -Force
# NOTE: the app copy keeps its own public/build — Laravel resolves the Vite
# manifest via public_path() (stunningtouch-app/public), while the document
# root serves the actual /build asset URLs.

$indexPhp = @'
<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Layout 1: the Laravel application lives OUTSIDE the document root,
// in /home/USERNAME/stunningtouch-app. Only this index.php, .htaccess,
// compiled assets and the temporary installer are exposed.
require __DIR__.'/../stunningtouch-app/vendor/autoload.php';

$app = require __DIR__.'/../stunningtouch-app/bootstrap/app.php';

$app->handleRequest(Request::capture());
'@
Set-Content -LiteralPath (Join-Path $htmlDir 'index.php') -Value $indexPhp -Encoding ASCII

Copy-Item (Join-Path $root 'public\.htaccess') (Join-Path $htmlDir '.htaccess') -Force

# ---------------------------------------------------------------------------
# 6. Secure installer + authorization key
# ---------------------------------------------------------------------------
Write-Host "-> preparing installer" -ForegroundColor Gray

Copy-Item (Join-Path $PSScriptRoot 'installer-src\install.php') (Join-Path $insDir 'install.php') -Force

# Cryptographically secure installation key — 64 hex chars from 32 random bytes.
$tokenBytes = New-Object byte[] 32
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$rng.GetBytes($tokenBytes)
$rng.Dispose()
$installKey = [BitConverter]::ToString($tokenBytes).Replace('-', '').ToLowerInvariant()
Set-Content -LiteralPath (Join-Path $insDir 'INSTALL-KEY.txt') -Value $installKey -NoNewline:$false
Set-Content -LiteralPath $keyLocal -Value "INSTALLER KEY (keep private, give to the installer operator):`r`n$installKey`r`n" 

# ---------------------------------------------------------------------------
# 7. Guides
# ---------------------------------------------------------------------------
Copy-Item (Join-Path $PSScriptRoot 'INSTALL.txt') (Join-Path $pkg 'INSTALL.txt') -Force
Copy-Item (Join-Path $PSScriptRoot 'root-public-htaccess.txt') (Join-Path $pkg 'LAYOUT2-root-public-htaccess.txt') -Force

# ---------------------------------------------------------------------------
# 8. Package integrity verification
# ---------------------------------------------------------------------------
Write-Host "-> verifying package" -ForegroundColor Gray

Check (Test-Path (Join-Path $appDir 'vendor\autoload.php')) "Package check failed: vendor/autoload.php missing."
Check (Test-Path (Join-Path $appDir 'artisan')) "Package check failed: artisan missing."
Check (Test-Path (Join-Path $appDir 'bootstrap\app.php')) "Package check failed: bootstrap/app.php missing."
Check (Test-Path (Join-Path $htmlDir 'build\manifest.json')) "Package check failed: public_html/build/manifest.json missing."
Check (Test-Path (Join-Path $insDir 'install.php')) "Package check failed: installer/install.php missing."
Check (Test-Path (Join-Path $insDir 'INSTALL-KEY.txt')) "Package check failed: installer key missing."
Check (-not (Test-Path (Join-Path $appDir '.env'))) "Package check failed: .env must never be packaged."

# Environment template must contain placeholders only.
$template = Get-Content (Join-Path $appDir '.env.production.template') -Raw
Check ($template -notmatch '(?m)^(DB_PASSWORD|APP_KEY|SCHEDULER_SECRET)=[^\sC][^\r\n]*$') ".env.production.template contains a non-placeholder value — refusing to package."

# Scan the staged tree for forbidden files (placeholders .gitignore and
# .env.production.template are intentional).
$forbidden = Get-ChildItem $pkg -Recurse -Force -File | Where-Object {
    ($_.Name -eq '.env') -or
    ($_.Name -like '.env.*' -and $_.Name -ne '.env.production.template') -or
    ($_.Extension -in '.zip', '.sqlite', '.log', '.bak', '.pem', '.key') -or
    ($_.Name -eq 'Thumbs.db')
}
Check (-not $forbidden) ("Forbidden files in package: " + (($forbidden | ForEach-Object { $_.FullName.Substring($pkg.Length) }) -join ', '))

# ---------------------------------------------------------------------------
# 9. Create ZIP + checksum
# ---------------------------------------------------------------------------
Write-Host "-> creating $zip" -ForegroundColor Gray

# Build the ZIP with the System.IO.Compression API and FORWARD-SLASH entry
# names. PowerShell 5.1's Compress-Archive writes backslash separators,
# which non-Windows extractors (cPanel!) mishandle — nested vendor files
# go missing on the server. Per-file retry absorbs transient local locks.
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

if (Test-Path $zip) { Remove-Item $zip -Force }

$archiveStream = [System.IO.File]::Open($zip, [System.IO.FileMode]::CreateNew)
$archive = New-Object System.IO.Compression.ZipArchive($archiveStream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $baseLen = $pkg.Length + 1
    $files = Get-ChildItem $pkg -Recurse -File
    Check ($files.Count -gt 100) "Staging directory unexpectedly small ($($files.Count) files)."
    foreach ($file in $files) {
        $entryName = $file.FullName.Substring($baseLen).Replace('\', '/')
        $copied = $false
        for ($try = 1; $try -le 5 -and -not $copied; $try++) {
            try {
                $entry = $archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
                $entryStream = $entry.Open()
                $fileStream = [System.IO.File]::OpenRead($file.FullName)
                try {
                    $fileStream.CopyTo($entryStream)
                    $copied = $true
                } finally {
                    $fileStream.Dispose()
                    $entryStream.Dispose()
                }
            } catch {
                if ($try -eq 5) { Fail "Could not read '$($file.FullName)' after 5 attempts: $($_.Exception.Message)" }
                Start-Sleep -Milliseconds 600
            }
        }
    }
} finally {
    $archive.Dispose()
    $archiveStream.Dispose()
}

$hash = (Get-FileHash -Algorithm SHA256 $zip).Hash.ToLowerInvariant()
Set-Content -LiteralPath $zipSha -Value "$hash  stunningtouch-deploy.zip"
$size = "{0:N2}" -f ((Get-Item $zip).Length / 1MB)

# ZIP content scan: no .env, no private files inside the archive.
Add-Type -AssemblyName System.IO.Compression.FileSystem 2>$null
$zipEntries = [System.IO.Compression.ZipFile]::OpenRead($zip)
try {
    $badEntries = $zipEntries.Entries | Where-Object {
        ($_.FullName -match '(^|[\\/])\.env$') -or
        ($_.FullName -match '\.env\.' -and $_.FullName -notmatch '\.env\.production\.template$') -or
        ($_.FullName -match '\.(sqlite|log|pem|key)$')
    }
    Check (-not $badEntries) ("ZIP contains forbidden entries: " + (($badEntries | ForEach-Object { $_.FullName }) -join ', '))
} finally { $zipEntries.Dispose() }

# ---------------------------------------------------------------------------
# 10. Restore the local development environment
# ---------------------------------------------------------------------------
Write-Host "-> restoring local dev dependencies (composer install)" -ForegroundColor Gray
Push-Location $root
try {
    & composer install --no-interaction --quiet 2>&1 | Out-Null
    Check ($LASTEXITCODE -eq 0) "composer install (dev restore) failed — run it manually."
} finally { Pop-Location }

Write-Host ""
Write-Host "Done." -ForegroundColor Green
Write-Host "  ZIP:      $zip ($size MB)"
Write-Host "  SHA-256:  $hash"
Write-Host "  Installer key: $keyLocal (KEEP PRIVATE — never commit or email)"
Write-Host "  Upload the ZIP via cPanel File Manager, extract in /home/USERNAME/, open"
Write-Host "  https://yourdomain.com/installer/install.php and follow INSTALL.txt."
