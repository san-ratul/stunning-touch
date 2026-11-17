<?php

namespace App\Support;

/**
 * Strict SSRF guard for the public SMTP Connection & Email Tester.
 *
 * Unlike App\Support\EndpointGuard (admin-only, intentionally allows
 * loopback/private targets for locally hosted model servers), this guard
 * runs on an anonymous public endpoint and must reject every non-public,
 * non-routable destination:
 *
 * - cloud metadata hostnames and all link-local addresses,
 * - loopback, private, shared-address, carrier-grade NAT, reserved,
 *   benchmark and documentation ranges (IPv4 and IPv6),
 * - IPv4-mapped / IPv4-compatible IPv6 forms (checked as IPv4),
 * - hosts whose DNS records resolve to any prohibited address.
 *
 * The resolved, validated IP list is returned so the caller can connect
 * to a previously validated address, narrowing DNS-rebinding windows.
 */
class SmtpEndpointGuard
{
    /** @var list<string> */
    protected const BLOCKED_HOSTNAMES = [
        'metadata.google.internal',
        'metadata.goog',
        'instance-data',
        'metadata',
    ];

    /** @var array<string, list<int[]>> prohibited IPv4 [prefix, bits] ranges */
    protected const BLOCKED_IPV4 = [
        '0.0.0.0/8' => [[0x00000000, 8]],          // "this" network
        '10.0.0.0/8' => [[0x0A000000, 8]],         // private
        '100.64.0.0/10' => [[0x64400000, 10]],     // carrier-grade NAT
        '127.0.0.0/8' => [[0x7F000000, 8]],        // loopback
        '169.254.0.0/16' => [[0xA9FE0000, 16]],    // link-local / cloud metadata
        '172.16.0.0/12' => [[0xAC100000, 12]],     // private
        '192.0.0.0/24' => [[0xC0000000, 24]],      // IETF protocol assignments
        '192.0.2.0/24' => [[0xC0000200, 24]],      // TEST-NET-1
        '192.168.0.0/16' => [[0xC0A80000, 16]],    // private
        '198.18.0.0/15' => [[0xC6120000, 15]],     // benchmarking
        '198.51.100.0/24' => [[0xC6336400, 24]],   // TEST-NET-2
        '203.0.113.0/24' => [[0xCB007100, 24]],    // TEST-NET-3
        '224.0.0.0/4' => [[0xE0000000, 4]],        // multicast
        '240.0.0.0/4' => [[0xF0000000, 4]],        // reserved (incl. broadcast)
    ];

    /** @var array<string, array{0: string, 1: int}> prohibited IPv6 prefixes (bytes prefix, bits) */
    protected const BLOCKED_IPV6 = [
        '::/128' => ["\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00", 128], // unspecified
        '::1/128' => ["\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01", 128], // loopback
        '::ffff:0:0/96' => ["\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff", 96], // IPv4-mapped
        '::/96' => ["\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00", 96], // IPv4-compatible (deprecated)
        'fc00::/7' => ["\xfc", 7],                      // unique local
        'fe80::/10' => ["\xfe\x80", 10],                // link-local
        '2001:db8::/32' => ["\x20\x01\x0d\xb8", 32],    // documentation
        'ff00::/8' => ["\xff", 8],                      // multicast
    ];

    protected const IPV6_MAP_OFFSET = 12;

    /**
     * Validate an SMTP host and resolve it to public IPs.
     *
     * @return array{error: ?string, ips: list<string>}
     */
    public static function resolve(string $host): array
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0b"));

        if ($host === '') {
            return ['error' => 'The SMTP host is required.', 'ips' => []];
        }

        $host = self::stripBrackets($host);

        if (! self::validHostFormat($host)) {
            return ['error' => 'The SMTP host is not a valid hostname or IP address.', 'ips' => []];
        }

        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            return ['error' => 'This host is not allowed (cloud metadata service).', 'ips' => []];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::classify($host, $host);
        }

        $ips = self::dnsRecords($host);

        if ($ips === []) {
            return ['error' => 'The SMTP host could not be resolved. Check the spelling or DNS records.', 'ips' => []];
        }

        foreach ($ips as $ip) {
            $result = self::classify($ip, $host);

            if ($result['error'] !== null) {
                return $result;
            }
        }

        return ['error' => null, 'ips' => $ips];
    }

    /**
     * Classify one IP address.
     *
     * @return array{error: ?string, ips: list<string>}
     */
    protected static function classify(string $ip, string $host): array
    {
        if (str_contains($ip, ':')) {
            $bytes = @inet_pton($ip);

            if ($bytes === false || strlen($bytes) !== 16) {
                return ['error' => 'The SMTP host is not a valid IP address.', 'ips' => []];
            }

            // IPv4-mapped / IPv4-compatible endings must pass IPv4 checks too.
            if (substr_compare($bytes, str_repeat("\0", self::IPV6_MAP_OFFSET), 0, self::IPV6_MAP_OFFSET) === 0) {
                $v4 = long2ip((int) unpack('N', substr($bytes, self::IPV6_MAP_OFFSET))[1]);

                if (self::blockedIpv4($v4)) {
                    return self::prohibited($host);
                }
            }

            if (self::blockedIpv6($bytes)) {
                return self::prohibited($host);
            }

            return ['error' => null, 'ips' => [$ip]];
        }

        if (self::blockedIpv4($ip)) {
            return self::prohibited($host);
        }

        return ['error' => null, 'ips' => [$ip]];
    }

    protected static function prohibited(string $host): array
    {
        return ['error' => 'The SMTP host resolves to a private, loopback or otherwise prohibited network address.', 'ips' => []];
    }

    protected static function blockedIpv4(string $ip): bool
    {
        $long = @ip2long($ip);

        if ($long === false || $long === 0) {
            return true; // unparseable addresses never pass
        }

        foreach (self::BLOCKED_IPV4 as $ranges) {
            foreach ($ranges as [$prefix, $bits]) {
                if (($long & ((-1 << (32 - $bits)) & 0xFFFFFFFF)) === $prefix) {
                    return true;
                }
            }
        }

        return false;
    }

    protected static function blockedIpv6(string $bytes): bool
    {
        foreach (self::BLOCKED_IPV6 as [$prefix, $bits]) {
            $fullPrefixLength = intdiv($bits, 8);
            $remainderBits = $bits % 8;

            if (substr_compare($bytes, $prefix, 0, $fullPrefixLength) !== 0) {
                continue;
            }

            if ($remainderBits === 0) {
                return true;
            }

            $mask = ((0xFF << (8 - $remainderBits)) & 0xFF);
            $byte = ord($bytes[$fullPrefixLength]);
            $expected = $fullPrefixLength < strlen($prefix) ? ord($prefix[$fullPrefixLength]) : 0;

            if (($byte & $mask) === ($expected & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Best-effort A/AAAA resolution.
     *
     * @return list<string>
     */
    protected static function dnsRecords(string $host): array
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

        // Fall back for environments where dns_get_record is unavailable.
        if ($ips === [] && filter_var(@gethostbyname($host), FILTER_VALIDATE_IP) !== false) {
            $ips[] = gethostbyname($host);
        }

        return $ips;
    }

    protected static function stripBrackets(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    /**
     * Accepts hostnames (letters, digits, hyphen labels) and IP literals.
     * Rejects ports, paths, credentials and other URL decorations.
     */
    protected static function validHostFormat(string $host): bool
    {
        // Bare IPv6 literals contain ':' — validate before rejecting colons.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return true;
        }

        if (strlen($host) > 253 || str_contains($host, '/') || str_contains($host, '@') || str_contains($host, ':')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }

            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $label)) {
                return false;
            }
        }

        return true;
    }
}
