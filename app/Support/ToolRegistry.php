<?php

namespace App\Support;

/**
 * Safe, hardcoded registry of functional tool implementations.
 *
 * Database records may only reference keys defined here. A component key
 * coming from the database can never execute arbitrary code: it either
 * matches a registered Blade view + JS module below, or the tool falls
 * back to a safe "maintenance" view.
 */
class ToolRegistry
{
    public const TOOLS = [
        'image-compressor' => [
            'view' => 'tools.image-compressor',
            'js' => 'tools/image-compressor.js',
            'accept' => 'image/jpeg,image/png,image/webp',
            'client_side' => true,
        ],
        'image-resizer' => [
            'view' => 'tools.image-resizer',
            'js' => 'tools/image-resizer.js',
            'accept' => 'image/jpeg,image/png,image/webp',
            'client_side' => true,
        ],
        'jpg-to-png' => [
            'view' => 'tools.jpg-to-png',
            'js' => 'tools/jpg-to-png.js',
            'accept' => 'image/jpeg',
            'client_side' => true,
        ],
        'png-to-webp' => [
            'view' => 'tools.png-to-webp',
            'js' => 'tools/png-to-webp.js',
            'accept' => 'image/png',
            'client_side' => true,
        ],
        'merge-pdf' => [
            'view' => 'tools.merge-pdf',
            'js' => 'tools/merge-pdf.js',
            'accept' => 'application/pdf',
            'client_side' => true,
        ],
        'split-pdf' => [
            'view' => 'tools.split-pdf',
            'js' => 'tools/split-pdf.js',
            'accept' => 'application/pdf',
            'client_side' => true,
        ],
        'word-counter' => [
            'view' => 'tools.word-counter',
            'js' => 'tools/word-counter.js',
            'accept' => null,
            'client_side' => true,
        ],
        'case-converter' => [
            'view' => 'tools.case-converter',
            'js' => 'tools/case-converter.js',
            'accept' => null,
            'client_side' => true,
        ],
        'json-formatter' => [
            'view' => 'tools.json-formatter',
            'js' => 'tools/json-formatter.js',
            'accept' => null,
            'client_side' => true,
        ],
        'base64-encoder-decoder' => [
            'view' => 'tools.base64',
            'js' => 'tools/base64.js',
            'accept' => null,
            'client_side' => true,
        ],
        'qr-code-generator' => [
            'view' => 'tools.qr-code-generator',
            'js' => 'tools/qr-code-generator.js',
            'accept' => null,
            'client_side' => true,
        ],
        'uuid-generator' => [
            'view' => 'tools.uuid-generator',
            'js' => 'tools/uuid-generator.js',
            'accept' => null,
            'client_side' => true,
        ],
    ];

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::TOOLS);
    }

    public static function config(string $key): ?array
    {
        return self::TOOLS[$key] ?? null;
    }

    public static function keys(): array
    {
        return array_keys(self::TOOLS);
    }
}
