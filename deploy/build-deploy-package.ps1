# Stunning Touch — deployment package builder
# Produces deploy/stunningtouch-deploy.zip for cPanel ZIP upload.
# Usage:  powershell -ExecutionPolicy Bypass -File deploy\build-deploy-package.ps1
# Requires: PHP + Composer locally; run `npm run build` first.

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$pkg  = Join-Path $PSScriptRoot "package"
$zip  = Join-Path $PSScriptRoot "stunningtouch-deploy.zip"

Write-Host "== Stunning Touch deployment package builder ==" -ForegroundColor Cyan

# 1. Verify build assets exist
if (-not (Test-Path (Join-Path $root "public\build\manifest.json"))) {
    Write-Error "public/build/manifest.json not found. Run 'npm run build' first."
}

# 2. Production composer install
Write-Host "-> composer install --no-dev --optimize-autoloader"
Push-Location $root
try {
    & composer install --no-dev --optimize-autoloader --no-interaction --quiet
    if ($LASTEXITCODE -ne 0) { throw "composer install failed" }
} finally { Pop-Location }

# 3. Clean previous package
if (Test-Path $pkg) { Remove-Item $pkg -Recurse -Force }
if (Test-Path $zip) { Remove-Item $zip -Force }
New-Item -ItemType Directory -Path $pkg | Out-Null

$appDir  = Join-Path $pkg "stunningtouch-app"
$htmlDir = Join-Path $pkg "public_html"
New-Item -ItemType Directory -Path $appDir | Out-Null
New-Item -ItemType Directory -Path $htmlDir | Out-Null

# 4. Copy app (excluding dev junk)
Write-Host "-> copying application files"
$copyDirs = @("app", "bootstrap", "config", "database", "lang", "public", "resources", "routes", "vendor")
foreach ($d in $copyDirs) {
    if (Test-Path (Join-Path $root $d)) {
        Copy-Item (Join-Path $root $d) $appDir -Recurse -Force
    }
}
foreach ($f in @("artisan", "composer.json", "composer.lock", "package.json", "README.md")) {
    if (Test-Path (Join-Path $root $f)) { Copy-Item (Join-Path $root $f) $appDir -Force }
}

# storage skeleton with writable subdirs
New-Item -ItemType Directory -Force -Path `
    (Join-Path $appDir "storage\app\public"), `
    (Join-Path $appDir "storage\framework\cache\data"), `
    (Join-Path $appDir "storage\framework\sessions"), `
    (Join-Path $appDir "storage\framework\views"), `
    (Join-Path $appDir "storage\logs") | Out-Null
"# generated" | Set-Content (Join-Path $appDir "storage\logs\.gitkeep")

# bootstrap/cache skeleton
New-Item -ItemType Directory -Force -Path (Join-Path $appDir "bootstrap\cache") | Out-Null

# 5. public_html contents = app/public + adjusted index.php
Write-Host "-> preparing public_html"
Copy-Item (Join-Path $appDir "public\*") $htmlDir -Recurse -Force

$indexPhp = @'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// App lives outside the document root: ../stunningtouch-app
require __DIR__.'/../stunningtouch-app/vendor/autoload.php';

$app = require __DIR__.'/../stunningtouch-app/bootstrap/app.php';

$app->handleRequest(Request::capture());
'@
Set-Content -LiteralPath (Join-Path $htmlDir "index.php") -Value $indexPhp -Encoding ASCII

# public/.htaccess for the document root (production hardening)
Copy-Item (Join-Path $root "public\.htaccess") (Join-Path $htmlDir ".htaccess") -Force

# 6. Env template + INSTALL instructions
Copy-Item (Join-Path $PSScriptRoot ".env.production.template") (Join-Path $appDir ".env.production.template") -Force
Copy-Item (Join-Path $PSScriptRoot "root-public-htaccess.txt") (Join-Path $pkg "LAYOUT2-root-public-htaccess.txt") -Force
Copy-Item (Join-Path $PSScriptRoot "INSTALL.txt") (Join-Path $pkg "INSTALL.txt") -Force

# 7. Zip
Write-Host "-> creating $zip"
Compress-Archive -Path (Join-Path $pkg "*") -DestinationPath $zip -CompressionLevel Optimal

$size = "{0:N1}" -f ((Get-Item $zip).Length / 1MB)
Write-Host "Done: $zip ($size MB)" -ForegroundColor Green
Write-Host "Upload via cPanel File Manager, extract in /home/USERNAME/, follow INSTALL.txt"
