<?php

namespace App\Services\Ai;

use App\Services\Research\ResearchResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Derives keyword suggestions from real research data and site content.
 *
 * Honesty rules:
 * - No search volume or keyword difficulty is ever estimated or fabricated.
 * - `volume_data_available` is always false until a real keyword data
 *   provider is configured (none exists in this MVP).
 * - Suggestions are derived from actual research titles/snippets and the
 *   topic itself.
 */
class KeywordService
{
    protected const STOPWORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'your', 'you', 'how', 'what', 'when', 'why', 'are', 'can',
        'best', 'top', 'new', 'our', 'not', 'but', 'all', 'any', 'out', 'use', 'using', 'into', 'more', 'than', 'about',
        'will', 'have', 'has', 'was', 'were', 'they', 'their', 'them', 'there', 'then', 'these', 'those', 'also', 'get',
        'one', 'two', 'three', 'way', 'ways', 'guide', 'tips', 'free', 'online', 'tools', 'tool',
    ];

    /**
     * @return array{
     *     focus: string|null,
     *     secondary: list<string>,
     *     intent: string,
     *     volume_data_available: bool,
     *     note: string,
     * }
     */
    public function suggest(string $topic, ?Collection $researchResults = null, ?string $preferredKeywords = null): array
    {
        $corpus = Str::lower($topic);

        foreach (($researchResults ?? collect()) as $result) {
            if ($result instanceof ResearchResult) {
                $corpus .= ' '.Str::lower($result->title.' '.$result->snippet);
            }
        }

        $terms = collect(preg_split('/[^a-z0-9]+/', $corpus, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->filter(fn ($t) => mb_strlen($t) >= 4 && ! in_array($t, self::STOPWORDS, true))
            ->countBy()
            ->sortDesc();

        $topicWords = collect(preg_split('/[^a-z0-9]+/', Str::lower($topic), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->filter(fn ($t) => mb_strlen($t) >= 3 && ! in_array($t, self::STOPWORDS, true))
            ->values();

        $focus = $topicWords->isNotEmpty() ? implode(' ', $topicWords->take(3)->all()) : null;

        $secondary = $terms
            ->keys()
            ->reject(fn ($term) => $topicWords->contains($term))
            ->take(6)
            ->values()
            ->all();

        $preferred = collect(preg_split('/[,;]+/', (string) $preferredKeywords))
            ->map(fn ($k) => trim($k))
            ->filter()
            ->values();

        if ($preferred->isNotEmpty()) {
            $secondary = $preferred->merge($secondary)->unique()->take(6)->values()->all();
            $focus ??= $preferred->first();
        }

        return [
            'focus' => $focus,
            'secondary' => array_values(array_slice($secondary, 0, 6)),
            'intent' => $this->detectIntent($topic),
            'volume_data_available' => false,
            'note' => 'No keyword volume provider is configured; volumes and difficulty are not estimated or fabricated.',
        ];
    }

    public function detectIntent(string $topic): string
    {
        $t = Str::lower($topic);

        return match (true) {
            (bool) preg_match('/\bhow to\b|\bstep[s]? (by|to)\b|\btutorial\b|\bguide\b/', $t) => 'how-to',
            (bool) preg_match('/\bvs\b|\bversus\b|\bcompare\b|\bcomparison\b|\bbest\b|\btop \d+/', $t) => 'comparison',
            (bool) preg_match('/\bbuy\b|\bprice\b|\bpricing\b|\bdownload\b|\bhire\b|\bcoupon\b/', $t) => 'transactional',
            default => 'informational',
        };
    }
}
