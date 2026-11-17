<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bot Detection, Blocking & Analytics
    |--------------------------------------------------------------------------
    |
    | Application-managed bot protection for shared hosting: no Cloudflare,
    | no Redis, no queue workers. All settings below can be overridden at
    | runtime by authorized administrators via the Site Settings store
    | (Admin → Analytics → Bot Protection). Client-submitted values such as
    | is_bot / is_human are never consulted — classification is
    | server-side only.
    |
    */

    'enabled' => env('BOT_PROTECTION_ENABLED', true),

    // Forward-confirmed reverse-DNS verification of claimed search crawlers.
    // User-Agent alone is never sufficient proof of identity.
    'verify_crawlers' => env('BOT_VERIFY_CRAWLERS', true),

    // Cache TTLs for crawler verification results (positive = verified).
    'verification_cache' => [
        'verified_ttl' => 60 * 24,      // minutes
        'failed_ttl' => 60,             // minutes
    ],

    // Per-minute, per-IP GET limits for automated clients on normal pages.
    // Overridable via Site Settings (Admin → Bot Protection).
    'page_rate_limit' => env('BOT_PAGE_RATE_LIMIT', 60),
    'suspicious_rate_limit' => env('BOT_SUSPICIOUS_RATE_LIMIT', 30),

    // Tool usage beacon dedupe window (seconds): identical
    // (visitor, tool, event, success) submissions inside the window are
    // rejected as duplicates instead of being recorded.
    'tool_dedupe_window' => env('BOT_TOOL_DEDUPE_WINDOW', 60),

];
