<?php

namespace App\Services\Smtp;

use RuntimeException;

/**
 * Raised for any SMTP session failure. Messages are written for internal
 * logging only — the service layer maps them to sanitized user-facing text
 * and never forwards them raw to the client.
 */
class SmtpConnectionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $kind = 'connection',
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Translate a raw socket error into a stable, exception "kind" the
     * service layer can map to a sanitized user-facing message.
     */
    public static function fromSocketError(int $code, string $message, int $timeoutSeconds): self
    {
        $lower = strtolower($message);

        return new self(
            $message !== '' ? $message : "Could not connect to the SMTP server (error code {$code}).",
            kind: match (true) {
                $code === 110 || str_contains($lower, 'timed out') || $code === 60 => 'timeout',
                $code === 111 || str_contains($lower, 'refused') => 'refused',
                $code === -2 || $code === 0 || str_contains($lower, 'name or service not known') || str_contains($lower, 'getaddrinfo') => 'dns',
                default => 'connection',
            },
            detail: $code.' '.$message,
        );
    }
}
