<?php

namespace App\Support;

/**
 * Verifies claimed search-crawler identity server-side.
 *
 * Implementations must never perform network I/O on the hot path for
 * requests that do not claim to be a search crawler, and should cache
 * their results.
 */
interface CrawlerVerifier
{
    public const VERIFIED = 'verified';

    public const FAILED = 'failed';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  string  $ip  client IP making the request
     * @param  string  $userAgent  claimed crawler user agent
     * @param  string  $family  crawler family (googlebot, bingbot, ...)
     * @return string one of the VERIFIED / FAILED / UNAVAILABLE constants
     */
    public function verify(string $ip, string $userAgent, string $family): string;
}
