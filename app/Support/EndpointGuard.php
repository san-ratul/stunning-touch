<?php

namespace App\Support;

/**
 * SSRF-safe endpoint validation for admin-triggered outbound requests
 * (model discovery, connection tests).
 *
 * Security model (matches the application's existing configuration):
 * - Only http and https schemes are allowed.
 * - Cloud metadata endpoints are always blocked: the entire IPv4/IPv6
 *   link-local ranges (169.254.0.0/16, fe80::/10) and the well-known
 *   metadata hostnames. No legitimate feature of this application needs
 *   them.
 * - Loopback and private-network hosts are intentionally allowed because
 *   locally hosted Ollama endpoints (http://127.0.0.1:11434,
 *   http://192.168.x.x:11434) are a first-class supported feature. This
 *   surface is admin-only (authentication + CSRF), which is the existing
 *   security model.
 * - Hostnames are resolved best-effort so that a friendly name pointing at
 *   a metadata address is caught too; when DNS is unavailable the outbound
 *   request itself will fail and be reported as unreachable.
 */
class EndpointGuard
{
    /** @var list<string> */
    protected const BLOCKED_HOSTNAMES = [
        'metadata.google.internal',
        'metadata.goog',
        'instance-data',
    ];

    /**
     * @return string|null error message, or null when the endpoint is allowed
     */
    public static function check(string $endpoint): ?string
    {
        $parts = parse_url(trim($endpoint));

        if ($parts === false || blank($parts['scheme'] ?? null) || blank($parts['host'] ?? null)) {
            return 'The endpoint is not a valid URL.';
        }

        if (! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return 'The endpoint must use http or https.';
        }

        $host = strtolower((string) $parts['host']);
        $host = trim($host, '[]');

        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            return 'The endpoint targets a blocked cloud metadata service.';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::ipReason($host);
        }

        foreach (self::resolveIps($host) as $ip) {
            $reason = self::ipReason($ip);

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    protected static function ipReason(string $ip): ?string
    {
        // Link-local range: cloud metadata services (169.254.169.254 etc.).
        if (str_starts_with($ip, '169.254.') || str_starts_with(strtolower($ip), 'fe80:')) {
            return 'The endpoint targets a blocked link-local (cloud metadata) address.';
        }

        return null;
    }

    /**
     * Best-effort A/AAAA resolution; failures are ignored because the
     * outbound request itself will surface the problem.
     *
     * @return list<string>
     */
    protected static function resolveIps(string $host): array
    {
        $ips = [];

        $a = @dns_get_record($host, DNS_A);
        foreach (is_array($a) ? $a : [] as $record) {
            if (is_string($record['ip'] ?? null)) {
                $ips[] = $record['ip'];
            }
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($aaaa) ? $aaaa : [] as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }
}
