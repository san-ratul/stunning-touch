<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Immutable server-side traffic classification verdict.
 *
 * Built exclusively from server-observable signals (request method, path,
 * headers, client IP). Client-submitted fields such as `is_bot` or
 * `traffic_classification` are never consulted.
 */
class TrafficVerdict
{
    public const HUMAN = 'human';

    public const VERIFIED_SEARCH_BOT = 'verified_search_bot';

    public const KNOWN_BOT = 'known_bot';

    public const SUSPICIOUS = 'suspicious';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $category,
        public readonly string $reason,
        public readonly ?string $botName = null,
    ) {}

    /**
     * Categories that are certainly or probably automated.
     */
    public function isBot(): bool
    {
        return in_array($this->category, [
            self::VERIFIED_SEARCH_BOT,
            self::KNOWN_BOT,
            self::SUSPICIOUS,
        ], true);
    }

    public function isSuspicious(): bool
    {
        return $this->category === self::SUSPICIOUS;
    }
}
