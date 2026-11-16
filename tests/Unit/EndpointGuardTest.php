<?php

namespace Tests\Unit;

use App\Support\EndpointGuard;
use Tests\TestCase;

class EndpointGuardTest extends TestCase
{
    public function test_blocks_cloud_metadata_ip_endpoints(): void
    {
        $this->assertStringContainsString(
            'link-local',
            (string) EndpointGuard::check('http://169.254.169.254/latest/meta-data/')
        );
    }

    public function test_blocks_ipv6_link_local_endpoints(): void
    {
        $this->assertStringContainsString(
            'link-local',
            (string) EndpointGuard::check('http://[fe80::1]:8080/models')
        );
    }

    public function test_blocks_cloud_metadata_hostnames(): void
    {
        $this->assertStringContainsString(
            'metadata',
            (string) EndpointGuard::check('https://metadata.google.internal/computeMetadata/v1/')
        );
    }

    public function test_blocks_non_http_schemes(): void
    {
        $this->assertStringContainsString(
            'http or https',
            (string) EndpointGuard::check('ftp://files.example.com/models')
        );
    }

    public function test_rejects_unparseable_endpoints(): void
    {
        $this->assertStringContainsString('not a valid URL', (string) EndpointGuard::check('not-a-url'));
    }

    public function test_allows_local_ollama_loopback_endpoints(): void
    {
        $this->assertNull(EndpointGuard::check('http://127.0.0.1:11434/api/chat'));
        $this->assertNull(EndpointGuard::check('http://localhost:11434/api/chat'));
    }

    public function test_allows_public_https_endpoints(): void
    {
        $this->assertNull(EndpointGuard::check('https://api.groq.com/openai/v1'));
    }

    public function test_allows_private_network_hosts_for_local_providers(): void
    {
        $this->assertNull(EndpointGuard::check('http://192.168.1.20:11434/api/chat'));
    }
}
