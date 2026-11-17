<?php

namespace App\Services\Smtp;

use App\Support\SmtpEndpointGuard;

/**
 * Real socket-backed SMTP connection using stream functions — no external
 * network packages, compatible with cPanel shared hosting.
 *
 * Security properties:
 * - Connects to a previously validated IP (SmtpEndpointGuard) instead of the
 *   hostname, so attacker-controlled DNS cannot rebind mid-session.
 * - TLS peer verification uses the user-supplied hostname (peer_name), so
 *   certificates are still validated against the name the user typed.
 * - Credentials passed via send() are never stored or logged.
 */
final class SocketSmtpConnection implements SmtpConnectionInterface
{
    /** @var resource|null */
    private $stream = null;

    private int $latencyMs = 0;

    public function __construct(
        private readonly string $host,
        private readonly string $ip,
        private readonly int $port,
        private readonly string $encryption,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function connect(): void
    {
        $this->assertValidIp();

        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        // IPv6 literals must be bracketed in stream URLs.
        $destination = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;

        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $this->host,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
        ]);

        $errorCode = 0;
        $errorMessage = '';
        $started = microtime(true);

        $stream = @stream_socket_client(
            sprintf('%s://%s:%d', $scheme, $destination, $this->port),
            $errorCode,
            $errorMessage,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        $this->latencyMs = (int) round((microtime(true) - $started) * 1000);

        if ($stream === false) {
            $this->stream = null;

            throw SmtpConnectionException::fromSocketError($errorCode, $errorMessage, $this->timeoutSeconds);
        }

        $this->stream = $stream;
        stream_set_timeout($this->stream, $this->timeoutSeconds);
    }

    public function readResponse(): SmtpResponse
    {
        $stream = $this->requireStream();

        $lines = [];
        $code = 0;
        $timedOut = false;

        while (true) {
            $line = fgets($stream, 1024);

            if ($line === false || $line === '') {
                $timedOut = true;
                break;
            }

            $line = rtrim($line, "\r\n");

            if (preg_match('/^(\d{3})(?:[ \-](.*))?$/', $line, $matches) === 1) {
                $code = (int) $matches[1];
                $lines[] = $matches[2] ?? '';

                // Space after the code marks the final line of the response.
                if (strlen($line) === 3 || $line[3] === ' ') {
                    break;
                }
            } else {
                $lines[] = $line;

                // A response must not exceed sane bounds (banner flood guard).
                if (count($lines) > 64) {
                    break;
                }
            }
        }

        if ($code === 0) {
            $this->close();

            throw new SmtpConnectionException(
                $timedOut ? 'Timed out while waiting for an SMTP response.' : 'The connection was closed before a complete SMTP response arrived.',
                kind: 'response',
            );
        }

        return new SmtpResponse($code, $lines);
    }

    public function send(string $command): void
    {
        $stream = $this->requireStream();

        $written = @fwrite($stream, $command."\r\n");

        if ($written === false) {
            $this->close();

            throw new SmtpConnectionException('Failed to write to the SMTP connection.', 'write');
        }
    }

    public function startTls(): void
    {
        $stream = $this->requireStream();

        $result = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if ($result !== true) {
            $this->close();

            throw new SmtpConnectionException('The TLS handshake failed.', 'tls');
        }
    }

    public function latencyMs(): int
    {
        return $this->latencyMs;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $this->stream = null;
    }

    private function assertValidIp(): void
    {
        // Re-assert at connect time: the IP must still be a public address.
        $check = SmtpEndpointGuard::resolve($this->ip);

        if ($check['error'] !== null) {
            throw new SmtpConnectionException('The destination address is not allowed.', 'blocked');
        }
    }

    /**
     * @return resource
     */
    private function requireStream()
    {
        if (! is_resource($this->stream)) {
            throw new SmtpConnectionException('The SMTP connection is not open.', 'connection');
        }

        return $this->stream;
    }
}
