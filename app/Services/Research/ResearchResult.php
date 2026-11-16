<?php

namespace App\Services\Research;

/**
 * A single normalized research finding fetched from a real external source.
 * URLs and titles always originate from provider responses — never
 * synthesized by the application.
 */
final class ResearchResult
{
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $snippet,
        public readonly string $sourceType,
        public readonly string $fetchedAt,
        public readonly ?int $relevanceScore = null,
        public readonly array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'snippet' => $this->snippet,
            'source_type' => $this->sourceType,
            'fetched_at' => $this->fetchedAt,
            'relevance_score' => $this->relevanceScore,
            'meta' => $this->meta,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            url: (string) $data['url'],
            title: (string) $data['title'],
            snippet: (string) ($data['snippet'] ?? ''),
            sourceType: (string) ($data['source_type'] ?? 'web'),
            fetchedAt: (string) ($data['fetched_at'] ?? now()->toIso8601String()),
            relevanceScore: isset($data['relevance_score']) ? (int) $data['relevance_score'] : null,
            meta: (array) ($data['meta'] ?? []),
        );
    }
}
