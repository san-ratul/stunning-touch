<?php

namespace Tests\Unit;

use App\Support\SmtpEndpointGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SmtpEndpointGuardTest extends TestCase
{
    #[DataProvider('prohibitedHostProvider')]
    public function test_rejects_prohibited_hosts(string $host): void
    {
        $result = SmtpEndpointGuard::resolve($host);

        $this->assertNotNull($result['error'], "Host {$host} must be rejected.");
        $this->assertSame([], $result['ips']);
    }

    public static function prohibitedHostProvider(): array
    {
        return [
            'loopback v4' => ['127.0.0.1'],
            'loopback v4 high' => ['127.9.9.9'],
            'private 10/8' => ['10.0.0.5'],
            'private 172.16/12' => ['172.20.1.1'],
            'private 192.168/16' => ['192.168.1.1'],
            'link-local metadata' => ['169.254.169.254'],
            'carrier-grade NAT' => ['100.64.0.1'],
            'this-network' => ['0.0.0.0'],
            'test-net-1' => ['192.0.2.9'],
            'test-net-2' => ['198.51.100.7'],
            'test-net-3' => ['203.0.113.5'],
            'benchmarking' => ['198.18.0.1'],
            'multicast' => ['224.0.0.1'],
            'reserved high' => ['240.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'loopback v6' => ['::1'],
            'unspecified v6' => ['::'],
            'link-local v6' => ['fe80::1'],
            'unique local v6' => ['fc00::1'],
            'multicast v6' => ['ff02::1'],
            'documentation v6' => ['2001:db8::1'],
            'v4-mapped loopback' => ['::ffff:127.0.0.1'],
            'metadata hostname' => ['metadata.google.internal'],
            'metadata short' => ['metadata.goog'],
            'metadata bare' => ['metadata'],
            'unresolvable' => ['nonexistent-host-for-tests.invalid'],
        ];
    }

    #[DataProvider('allowedHostProvider')]
    public function test_allows_public_hosts(string $host): void
    {
        $result = SmtpEndpointGuard::resolve($host);

        $this->assertNull($result['error'], "Host {$host} must be allowed.");
        $this->assertNotEmpty($result['ips']);
    }

    public static function allowedHostProvider(): array
    {
        return [
            'google dns' => ['8.8.8.8'],
            'cloudflare dns' => ['1.1.1.1'],
            'public ipv6' => ['2001:4860:4860::8888'],
        ];
    }

    #[DataProvider('malformedHostProvider')]
    public function test_rejects_malformed_hosts(string $host): void
    {
        $result = SmtpEndpointGuard::resolve($host);

        $this->assertNotNull($result['error']);
        $this->assertSame([], $result['ips']);
    }

    public static function malformedHostProvider(): array
    {
        return [
            'empty' => [''],
            'host with port' => ['smtp.example.com:587'],
            'url path' => ['smtp.example.com/path'],
            'userinfo' => ['user@example.com'],
            'label too long' => [str_repeat('a', 64).'.com'],
            'label invalid chars' => ['smtp_under_score.com'],
            'leading hyphen' => ['-smtp.example.com'],
        ];
    }

    public function test_rejects_hostnames_resolving_to_private_addresses(): void
    {
        // localhost resolves to loopback in every environment.
        $result = SmtpEndpointGuard::resolve('localhost');

        $this->assertNotNull($result['error']);
    }

    public function test_bracketed_ipv6_is_normalized(): void
    {
        $result = SmtpEndpointGuard::resolve('[::1]');

        $this->assertNotNull($result['error']);
    }
}
