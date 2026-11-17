<?php

namespace App\Support;

use RuntimeException;

/**
 * Rejected input for the Artisan Command Runner. Messages are written for
 * administrator display and never reveal internal paths or environment
 * details.
 */
class ArtisanCommandException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
