<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression tests for local HTTPS trust of internal-link validation.
 *
 * Root cause on the local dev machine: the browser (schannel/Windows trust
 * store) trusts the local root CA (e.g. Laragon's self-signed root used for
 * *.test sites), while PHP's cURL uses OpenSSL with its own CA bundle
 * (curl.cainfo) and rejected the same site with
 * "self-signed certificate (18)" — making the quality validator report
 * every internal link as unreachable.
 *
 * The fix appends the trusted local root CA to the PHP CA bundle;
 * TLS verification is never disabled. These tests assert both facts:
 *
 * - PHP cURL (verification ON) can reach the local HTTPS site, and
 * - an unrelated self-signed host is still rejected (no global bypass).
 *
 * Both tests are environment-guarded: they exercise a real *.test host only
 * when one is configured/resolvable, so CI and production test runs skip.
 * If a different local domain is used, set LOCAL_TLS_CHECK_URL.
 */
class LocalTlsTrustTest extends TestCase
{
    protected function targetUrl(): string
    {
        return (string) env('LOCAL_TLS_CHECK_URL', 'https://stunningtouch.test/merge-pdf');
    }

    protected function curlHead(string $url, int $timeout = 10): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_exec($ch);
        $result = [
            'errno' => curl_errno($ch),
            'error' => curl_error($ch),
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        ];
        curl_close($ch);

        return $result;
    }

    public function test_php_curl_with_verification_enabled_trusts_the_local_https_site(): void
    {
        $url = $this->targetUrl();
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! str_ends_with($host, '.test')) {
            $this->markTestSkipped("LOCAL_TLS_CHECK_URL does not target a local .test domain ({$url}).");
        }

        $result = $this->curlHead($url);

        // Server not running / domain not configured on this machine: skip.
        if (in_array($result['errno'], [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], true)) {
            $this->markTestSkipped("Local site not reachable for a TLS trust check: {$result['error']}");
        }

        $this->assertSame(
            0,
            $result['errno'],
            "PHP cURL must trust the local HTTPS site with TLS verification ON (curl error {$result['errno']}: {$result['error']}). ".
            'Fix: append the trusted local root CA to the php.ini curl.cainfo bundle — never disable verification.'
        );
        $this->assertSame(200, $result['status'], "Internal-link validation targets must return HTTP 200 (got {$result['status']}).");
    }

    public function test_verification_is_still_enforced_for_untrusted_certificates(): void
    {
        $result = $this->curlHead('https://self-signed.badssl.com/', 15);

        // Offline / blocked in this environment: skip rather than fail.
        if (in_array($result['errno'], [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT], true)) {
            $this->markTestSkipped("External control host not reachable: {$result['error']}");
        }

        $this->assertSame(
            CURLE_SSL_PEER_CERTIFICATE,
            $result['errno'],
            'A self-signed certificate from an unrelated host must STILL fail verification — the local CA fix must never become a global TLS bypass.'
        );
    }
}
