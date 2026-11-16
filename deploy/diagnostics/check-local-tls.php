<?php

/*
 * Local TLS trust diagnostic for the AI blogging internal-link validator.
 *
 * Run inside the real PHP runtime on a dev machine:
 *
 *   php deploy/diagnostics/check-local-tls.php
 *
 * It explains why a browser can reach https://<local-domain> while PHP's
 * cURL (OpenSSL, own CA bundle) reports the same URL as unreachable:
 * the browser trusts the local root CA via the Windows trust store
 * (schannel), PHP only trusts its configured curl.cainfo/openssl.cafile
 * bundle. It never disables TLS verification; it only reports.
 *
 * The fix (machine-level, verification stays ON) is to append the local
 * root CA — e.g. the Laragon root C:\laragon\etc\ssl\laragon.crt — to the
 * PHP CA bundle configured in php.ini (curl.cainfo).
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$url = rtrim((string) config('app.url'), '/').'/merge-pdf';

echo 'PHP: '.PHP_VERSION.' | cURL: '.curl_version()['version'].PHP_EOL;
echo 'curl.cainfo: '.(ini_get('curl.cainfo') ?: '(none)').PHP_EOL;
echo 'openssl.cafile: '.(ini_get('openssl.cafile') ?: '(none)').PHP_EOL;
echo 'Target: '.$url.PHP_EOL;
echo str_repeat('-', 60).PHP_EOL;

// 1. System curl (schannel/Windows trust store on Windows builds).
echo '[1] curl.exe (Windows trust store):'.PHP_EOL;
$lines = shell_exec('curl -Iv '.escapeshellarg($url).' 2>&1') ?: '';
foreach (preg_split('/\r?\n/', trim($lines)) as $line) {
    if (preg_match('/(HTTP\/|SSL certificate problem|error|Established|subject:|issuer:)/i', $line)) {
        echo '    '.$line.PHP_EOL;
    }
}

// 2. DNS.
echo '[2] DNS:'.PHP_EOL;
$ip = gethostbyname(parse_url($url, PHP_URL_HOST));
echo '    '.parse_url($url, PHP_URL_HOST).' -> '.$ip.PHP_EOL;

// 3. Peer certificate (issuer) — fetched without verification, for display.
echo '[3] Peer certificate issuer (fetched for display, no verification):'.PHP_EOL;
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_CERTINFO, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_exec($ch);
$certs = curl_getinfo($ch, CURLINFO_CERTINFO) ?: [];
curl_close($ch);
echo '    '.($certs[0]['Issuer'] ?? 'n/a').PHP_EOL;

// 4. PHP cURL WITH verification (the path the app actually uses).
echo '[4] PHP cURL, TLS verification ON (default app trust):'.PHP_EOL;
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_exec($ch);
$errno = curl_errno($ch);
echo '    ok='.var_export(curl_errno($ch) === 0, true).' errno='.$errno.' error='.curl_error($ch).PHP_EOL;
curl_close($ch);

// 5. Laravel HTTP client with verification ON (what QualityValidatorService uses).
echo '[5] Laravel Http::get, TLS verification ON:'.PHP_EOL;
try {
    $response = Http::timeout(15)->connectTimeout(10)->get($url);
    echo '    status='.$response->status().' successful='.var_export($response->successful(), true).PHP_EOL;
} catch (Throwable $e) {
    echo '    FAILED: '.get_class($e).': '.$e->getMessage().PHP_EOL;
}

echo str_repeat('-', 60).PHP_EOL;
echo 'Interpretation:'.PHP_EOL;
echo '- [4]/[5] fail with "self-signed certificate" while [1] succeeds ='.PHP_EOL;
echo '  the local root CA is missing from the PHP CA bundle.'.PHP_EOL;
echo '- Fix safely: append the local root CA (never disable verification).'.PHP_EOL;
