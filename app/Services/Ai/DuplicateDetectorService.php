<?php

namespace App\Services\Ai;

use App\Models\BlogPost;
use Illuminate\Support\Str;

/**
 * Detects duplicate and near-duplicate blog topics before generation.
 *
 * Signals evaluated (per spec):
 * - normalized title similarity
 * - 3-word title shingles (Jaccard)
 * - slug collisions
 * - focus keyword overlap
 * - secondary keyword overlap
 *
 * The default threshold is 85%. At least the last 100 relevant posts are
 * evaluated when available. The service never rewrites or rejects posts —
 * it only reports.
 */
class DuplicateDetectorService
{
    public const DEFAULT_THRESHOLD = 85;

    /**
     * @return array{
     *     duplicate: bool,
     *     threshold: int,
     *     evaluated: int,
     *     best_match: array|null,
     *     candidates: array,
     * }
     */
    public function analyze(
        string $title,
        ?string $slug = null,
        ?string $focusKeyword = null,
        ?string $secondaryKeywords = null,
        ?int $excludePostId = null,
        ?int $threshold = null,
        int $limit = 100,
    ): array {
        $threshold ??= self::DEFAULT_THRESHOLD;

        $existing = BlogPost::query()
            ->when($excludePostId, fn ($q) => $q->whereKeyNot($excludePostId))
            ->latest('updated_at')
            ->limit($limit)
            ->get(['id', 'title', 'slug', 'focus_keyword', 'secondary_keywords', 'status']);

        $normalizedTitle = $this->normalize($title);
        $shingles = $this->shingles($normalizedTitle);
        $focus = $this->normalize((string) $focusKeyword);
        $secondary = $this->keywordList($secondaryKeywords);

        $candidates = [];
        $best = null;

        foreach ($existing as $post) {
            $postTitle = $this->normalize($post->title);
            $titleSimilarity = 0.0;
            similar_text($normalizedTitle, $postTitle, $titleSimilarity);
            $titleSimilarity = round($titleSimilarity, 1);

            $shingleOverlap = $this->shingleJaccard($shingles, $this->shingles($postTitle));

            $slugCollision = filled($slug) && filled($post->slug)
                && Str::slug($slug) === $post->slug;

            $postFocus = $this->normalize((string) $post->focus_keyword);
            $focusOverlap = $focus !== '' && $focus === $postFocus;

            $postSecondary = $this->keywordList((string) $post->secondary_keywords);
            $secondaryOverlap = $this->overlapPercent($secondary, $postSecondary);
            $secondaryShared = count(array_intersect($secondary, $postSecondary));

            $reasons = [];
            if ($slugCollision) {
                $reasons[] = 'slug collision';
            }
            if ($titleSimilarity >= $threshold) {
                $reasons[] = "title similarity {$titleSimilarity}%";
            }
            if ($shingleOverlap !== null && $shingleOverlap >= $threshold) {
                $reasons[] = "title shingle overlap {$shingleOverlap}%";
            }
            if ($focusOverlap) {
                $reasons[] = 'focus keyword "'.$focus.'" already targeted';
            }
            // Keyword overlap alone is weak evidence: admin keyword
            // preferences appear on every article, so small keyword sets can
            // overlap heavily for genuinely different topics. Only count it
            // when a substantial number of keywords is actually shared.
            if ($secondaryOverlap >= $threshold && $secondaryShared >= 4) {
                $reasons[] = "secondary keyword overlap {$secondaryOverlap}%";
            }

            $duplicate = $reasons !== [];

            $candidate = [
                'post_id' => $post->id,
                'title' => $post->title,
                'status' => $post->status,
                'title_similarity' => $titleSimilarity,
                'shingle_overlap' => $shingleOverlap,
                'slug_collision' => $slugCollision,
                'focus_keyword_overlap' => $focusOverlap,
                'secondary_keyword_overlap' => $secondaryOverlap,
                'secondary_keyword_shared' => $secondaryShared,
                'duplicate' => $duplicate,
                'reasons' => $reasons,
            ];

            $candidates[] = $candidate;

            if ($duplicate && ($best === null || $titleSimilarity > $best['title_similarity'])) {
                $best = $candidate;
            }
        }

        return [
            'duplicate' => $best !== null,
            'threshold' => $threshold,
            'evaluated' => $existing->count(),
            'best_match' => $best,
            'candidates' => $candidates,
        ];
    }

    public function isDuplicate(string $title, ?string $slug = null, ?string $focusKeyword = null, ?string $secondaryKeywords = null, ?int $excludePostId = null, ?int $threshold = null): bool
    {
        return $this->analyze($title, $slug, $focusKeyword, $secondaryKeywords, $excludePostId, $threshold)['duplicate'];
    }

    protected function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(trim($text))));
    }

    /**
     * @return list<string>|null 3-word shingles, or null when too short
     */
    protected function shingles(string $normalizedTitle): ?array
    {
        $words = preg_split('/[^a-z0-9]+/', $normalizedTitle, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || count($words) < 3) {
            return null;
        }

        $shingles = [];
        $count = count($words);

        for ($i = 0; $i <= $count - 3; $i++) {
            $shingles[] = implode(' ', array_slice($words, $i, 3));
        }

        return $shingles;
    }

    protected function shingleJaccard(?array $a, ?array $b): ?float
    {
        if ($a === null || $b === null) {
            return null;
        }

        if ($a === [] || $b === []) {
            return null;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        if ($union === 0) {
            return null;
        }

        return round(($intersection / $union) * 100, 1);
    }

    /**
     * @return list<string>
     */
    protected function keywordList(?string $keywords): array
    {
        return collect(preg_split('/[,;]+/', (string) $keywords))
            ->map(fn ($k) => $this->normalize($k))
            ->filter(fn ($k) => filled($k))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    protected function overlapPercent(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $shared = count(array_intersect($a, $b));
        $smaller = min(count($a), count($b));

        return round(($shared / $smaller) * 100, 1);
    }
}
