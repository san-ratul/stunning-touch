<?php

namespace App\Support;

/**
 * Single source of truth for the current environment's site URL.
 *
 * config('app.url') decides everything: local development points at
 * http://stunningtouch.test and production at https://stunningtouch.com.
 * No production domain is ever hardcoded into link generation or link
 * validation — each environment generates and validates its own URLs.
 */
class SiteUrl
{
    /**
     * Configured base URL without a trailing slash (port included when
     * configured, e.g. http://stunningtouch.test:8000).
     */
    public static function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * Normalized hostname of the configured site: lowercase, no port,
     * no leading "www.". Used for hostname-based internal-link matching
     * that ignores scheme (http/https) and optional ports.
     */
    public static function host(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (blank($host)) {
            return null;
        }

        return strtolower((string) preg_replace('/^www\./i', '', (string) $host));
    }
}
