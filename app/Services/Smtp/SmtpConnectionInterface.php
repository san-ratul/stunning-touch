<?php

namespace App\Services\Smtp;

/**
 * One SMTP session over a single socket.
 *
 * Implementations must never log commands or responses: credentials and
 * server banners pass through here, and credential hygiene belongs above.
 */
interface SmtpConnectionInterface
{
    /**
     * Open the socket (and negotiate implicit TLS when applicable).
     *
     * @throws SmtpConnectionException
     */
    public function connect(): void;

    /**
     * Read one complete SMTP response (all continuation lines).
     *
     * @throws SmtpConnectionException
     */
    public function readResponse(): SmtpResponse;

    /**
     * Write a command line (LF terminated).
     *
     * @throws SmtpConnectionException
     */
    public function send(string $command): void;

    /**
     * Upgrade the established plain connection to TLS (STARTTLS).
     *
     * @throws SmtpConnectionException
     */
    public function startTls(): void;

    /** Time to establish the socket connection, in milliseconds. */
    public function latencyMs(): int;

    public function close(): void;
}
