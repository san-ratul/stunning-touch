<?php

namespace App\Services\Smtp;

/**
 * Creates SMTP connections bound to a specific destination.
 *
 * The factory (not the connection) is container-resolved so tests can
 * substitute scripted fake connections without touching real sockets.
 */
interface SmtpConnectionFactoryInterface
{
    /**
     * @param  string  $ip  A pre-validated public IP from SmtpEndpointGuard —
     *                      connections target this address directly to narrow
     *                      DNS-rebinding windows.
     * @param  string  $host  The user-supplied hostname, preserved for TLS
     *                        certificate (SNI/peer_name) verification.
     * @param  'ssl'|'starttls'|'none'  $encryption
     */
    public function make(string $host, string $ip, int $port, string $encryption, int $timeoutSeconds = 10): SmtpConnectionInterface;
}
