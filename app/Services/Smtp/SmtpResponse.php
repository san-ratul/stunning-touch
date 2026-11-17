<?php

namespace App\Services\Smtp;

/**
 * One parsed SMTP response: three-digit code plus all response lines.
 */
final class SmtpResponse
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly int $code,
        public readonly array $lines,
    ) {}

    public function text(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * @return list<string> capability keywords found in a (multiline) EHLO response
     */
    public function capabilities(): array
    {
        $capabilities = [];

        foreach ($this->lines as $index => $line) {
            if ($index === 0) {
                continue; // "domain greeting" line
            }

            $keyword = trim($line);
            if ($keyword !== '') {
                $capabilities[] = $keyword;
            }
        }

        return $capabilities;
    }
}
