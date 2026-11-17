<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Forward-confirmed reverse DNS verification of claimed search crawlers.
 *
 * 1. Reverse-resolve the client IP to a hostname.
 * 2. The hostname must fall under the crawler's known domain.
 * 3. Forward-resolve that hostname and require the original IP back.
 *
 * Results are cached: verified claims for a day, failed claims for an
 * hour, so repeated lookups are never performed. Any DNS or cache failure
 * degrades to UNAVAILABLE (fail-safe: the claim is still treated as an
 * automated client, just not as a verified search engine).
 */
class ReverseDnsCrawlerVerifier implements CrawlerVerifier
{
    public function verify(string $ip, string $userAgent, string $family): string
    {
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return self::UNAVAILABLE;
        }

        // Loopback/private addresses can never be a real crawler.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return self::UNAVAILABLE;
        }

        $key = 'bot-verify-'.hash('sha256', $ip.'|'.$family);

        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached;
        }

        try {
            $result = $this->resolve($ip, $family);
        } catch (\Throwable) {
            $result = self::UNAVAILABLE;
        }

        $ttl = $result === self::VERIFIED
            ? (int) config('bot.verification_cache.verified_ttl', 1440)
            : (int) config('bot.verification_cache.failed_ttl', 60);

        try {
            Cache::put($key, $result, now()->addMinutes(max(1, $ttl)));
        } catch (\Throwable) {
            // A cache failure must not change the verdict.
        }

        return $result;
    }

    protected function resolve(string $ip, string $family): string
    {
        $host = @gethostbyaddr($ip);

        if ($host === false || $host === $ip) {
            return self::FAILED;
        }

        $allowedDomains = self::domainsFor($family);

        if ($allowedDomains === []) {
            return self::FAILED;
        }

        $host = strtolower(rtrim($host, '.'));

        $matches = false;

        foreach ($allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                $matches = true;
                break;
            }
        }

        if (! $matches) {
            return self::FAILED;
        }

        // Forward confirmation: the hostname must resolve back to the IP.
        $records = @dns_get_record($host, DNS_A) ?: [];

        foreach ($records as $record) {
            if (($record['ip'] ?? null) === $ip) {
                return self::VERIFIED;
            }
        }

        if ($records === []) {
            $forward = @gethostbyname($host);

            if ($forward !== $host && strtolower($forward) === strtolower($ip)) {
                return self::VERIFIED;
            }
        }

        return self::FAILED;
    }

    /** @return list<string> */
    protected function domainsFor(string $family): array
    {
        return TrafficClassifier::SEARCH_CRAWLERS[$family] ?? [];
    }
}
