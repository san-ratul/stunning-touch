<?php

namespace App\Services\Ai;

use App\Models\AiGeneration;
use App\Models\BlogPost;
use App\Models\QualityReport;
use App\Support\SiteUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Validates AI-generated articles before they may be auto-published.
 *
 * Mandatory check failures block auto-publishing; warnings never do.
 * The validator never modifies the article — it only reports and
 * persists a QualityReport for every validation run.
 *
 * Mandatory checks: word count bounds, SEO metadata, focus keyword
 * presence, heading structure, HTML validity, internal-link presence,
 * internal-link HTTP validation, duplicate detection, unsupported-claim
 * screening (when no research provenance exists), and research-source
 * requirements. Warnings: excerpt emptiness, heading-level jumps, missing
 * internal links when provenance exists, readability heuristics.
 */
class QualityValidatorService
{
    public const MIN_WORD_COUNT = 900;

    public const MAX_WORD_COUNT = 2000;

    protected const VOID_TAGS = ['br', 'hr', 'img', 'source', 'input', 'meta', 'link', 'area', 'base', 'col', 'embed', 'track', 'wbr'];

    /**
     * @param  array{
     *     min_word_count?: int|null,
     *     max_word_count?: int|null,
     *     duplicate_threshold?: int|null,
     *     research_sources?: Collection|null,
     *     research_required?: bool,
     *     internal_hosts?: array|null,
     * }  $options
     */
    public function validate(BlogPost $post, ?AiGeneration $generation = null, array $options = []): QualityReport
    {
        $minWords = max(1, (int) ($options['min_word_count'] ?? self::MIN_WORD_COUNT));
        $maxWords = max($minWords, (int) ($options['max_word_count'] ?? self::MAX_WORD_COUNT));

        $content = (string) $post->content;
        $text = trim(strip_tags($content));
        $wordCount = $text === '' ? 0 : str_word_count($text);
        $sourceRows = collect($options['research_sources'] ?? []);

        $violations = [];
        $warnings = [];
        $checks = [];

        // 1. Word count bounds
        $wordCountPassed = $wordCount >= $minWords && $wordCount <= $maxWords;
        $checks[] = ['name' => 'word_count', 'passed' => $wordCountPassed, 'details' => "{$wordCount} words (required {$minWords}-{$maxWords})"];
        if ($wordCount < $minWords) {
            $violations[] = "Article is too short: {$wordCount} words (minimum {$minWords}).";
        } elseif ($wordCount > $maxWords) {
            $violations[] = "Article is too long: {$wordCount} words (maximum {$maxWords}).";
        }

        // 2. Required SEO metadata
        $missingMeta = [];
        if (blank($post->seo_title)) {
            $missingMeta[] = 'SEO title';
        }
        if (blank($post->meta_description)) {
            $missingMeta[] = 'meta description';
        }
        if (blank($post->focus_keyword)) {
            $missingMeta[] = 'focus keyword';
        }
        $metaPassed = $missingMeta === [];
        $checks[] = ['name' => 'seo_metadata', 'passed' => $metaPassed, 'details' => $metaPassed ? 'SEO title, meta description and focus keyword present' : 'Missing: '.implode(', ', $missingMeta)];
        if (! $metaPassed) {
            $violations[] = 'Missing required SEO metadata: '.implode(', ', $missingMeta).'.';
        }

        if (blank($post->excerpt)) {
            $warnings[] = 'Excerpt is empty; listing pages and SEO fallbacks will degrade.';
        }

        // 3. Focus keyword presence in body text
        $focusKeyword = trim((string) $post->focus_keyword);
        if ($focusKeyword !== '') {
            $keywordPassed = mb_stripos($text, $focusKeyword) !== false;
            $checks[] = ['name' => 'focus_keyword_presence', 'passed' => $keywordPassed, 'details' => $keywordPassed ? "Focus keyword \"{$focusKeyword}\" appears in content" : 'Focus keyword missing from content'];
            if (! $keywordPassed) {
                $violations[] = "Focus keyword \"{$focusKeyword}\" does not appear in the article body.";
            }
        }

        // 4. Heading structure
        preg_match_all('/<h([1-6])[\s>]/i', $content, $headingMatches);
        $levels = array_map('intval', $headingMatches[1]);
        $hasH1 = in_array(1, $levels, true);
        $hasH2 = in_array(2, $levels, true);
        $headingPassed = ! $hasH1 && $hasH2;
        $checks[] = ['name' => 'heading_structure', 'passed' => $headingPassed, 'details' => $headingPassed ? 'No h1, at least one h2 present' : ($hasH1 ? 'Article must not contain an h1 heading' : 'Article needs at least one h2 heading')];
        if ($hasH1) {
            $violations[] = 'Article contains an h1 heading; body content must start at h2.';
        }
        if (! $hasH2) {
            $violations[] = 'Article has no h2 heading.';
        }

        $previousLevel = 0;
        foreach ($levels as $level) {
            if ($previousLevel > 0 && $level > $previousLevel + 1) {
                $warnings[] = "Heading levels jump from h{$previousLevel} to h{$level}; prefer incremental nesting.";
                break;
            }
            $previousLevel = $level;
        }

        // 5. Basic HTML validity (balanced non-void tags)
        $imbalance = $this->findTagImbalance($content);
        $htmlPassed = $imbalance === null;
        $checks[] = ['name' => 'html_validity', 'passed' => $htmlPassed, 'details' => $htmlPassed ? 'All tags balanced' : $imbalance];
        if (! $htmlPassed) {
            $violations[] = "HTML is not well formed: {$imbalance}.";
        }

        // 6. Internal links: at least one required, each HTTP-validated
        $internalLinks = $this->internalLinks($content, (array) ($options['internal_hosts'] ?? []));
        $linkDetails = [];
        $linksPassed = $internalLinks->count() > 0;
        $checks[] = ['name' => 'internal_links', 'passed' => $linksPassed, 'details' => "{$internalLinks->count()} internal link(s) found"];
        if (! $linksPassed) {
            $violations[] = 'Article contains no internal links; it must link to at least one relevant site page.';
        } else {
            foreach ($internalLinks->take(5) as $url) {
                $result = $this->checkLink($url);
                $linkDetails[] = ['url' => $url, 'ok' => $result['ok'], 'status' => $result['status']];

                if (! $result['ok']) {
                    $violations[] = "Internal link failed validation ({$url}): HTTP {$result['status']}.";
                }
            }

            $checks[] = ['name' => 'internal_link_http', 'passed' => collect($linkDetails)->every(fn ($l) => $l['ok']), 'details' => $linkDetails === [] ? 'no links to validate' : json_encode($linkDetails)];

            if ($internalLinks->count() === 1) {
                $warnings[] = 'Only one internal link found; 2-4 relevant links are recommended.';
            }
        }

        // 7. Duplicate detection (shingles, slugs, keywords, similarity)
        $threshold = (int) ($options['duplicate_threshold'] ?? DuplicateDetectorService::DEFAULT_THRESHOLD);
        $duplicateAnalysis = app(DuplicateDetectorService::class)->analyze(
            title: (string) $post->title,
            slug: (string) $post->slug,
            focusKeyword: (string) $post->focus_keyword,
            secondaryKeywords: (string) $post->secondary_keywords,
            excludePostId: $post->id,
            threshold: $threshold,
        );
        $duplicatePassed = ! $duplicateAnalysis['duplicate'];
        $checks[] = ['name' => 'duplicate_detection', 'passed' => $duplicatePassed, 'details' => $duplicatePassed ? "No duplicate among {$duplicateAnalysis['evaluated']} evaluated posts (threshold {$threshold}%)" : 'Matches: '.implode('; ', $duplicateAnalysis['best_match']['reasons'] ?? [])];
        if (! $duplicatePassed) {
            $violations[] = 'Duplicate topic: '.implode(', ', $duplicateAnalysis['best_match']['reasons'] ?? ['matches an existing post']).'.';
        }

        // 8. Unsupported claims: statistics/research phrasing must be backed
        // by linked research provenance, or they are not publishable.
        $claims = $this->findUnsupportedClaimPatterns($content);
        $claimsPassed = $claims === [];
        if ($claimsPassed) {
            $checks[] = ['name' => 'unsupported_claims', 'passed' => true, 'details' => 'No unsourced statistical claims detected'];
        } elseif ($sourceRows->isNotEmpty()) {
            // With research provenance the claims may trace to extracts; the
            // pipeline keeps them visible for review instead of blocking.
            $checks[] = ['name' => 'unsupported_claims', 'passed' => true, 'details' => 'Statistical phrasing present but article has '.count($sourceRows).' linked research source(s)'];
            $warnings[] = 'Statistical phrasing present (e.g. "'.Str::limit($claims[0], 80).'") — confirm it matches the linked research sources.';
        } else {
            $checks[] = ['name' => 'unsupported_claims', 'passed' => false, 'details' => 'Statistical claims without research provenance: '.count($claims)];
            foreach (array_slice($claims, 0, 3) as $claim) {
                $violations[] = 'Unsupported claim (no linked research): "'.Str::limit($claim, 120).'"';
            }
        }

        // 9. Research-source requirement
        $researchRequired = (bool) ($options['research_required'] ?? false);
        if ($researchRequired) {
            $researchPassed = $sourceRows->isNotEmpty();
            $checks[] = ['name' => 'research_sources', 'passed' => $researchPassed, 'details' => $researchPassed ? "{$sourceRows->count()} research source(s) linked" : 'No research sources linked'];
            if (! $researchPassed) {
                $violations[] = 'Research requirement not met: the article has no linked research sources.';
            }
        }

        // 10. Basic readability heuristics (warnings only)
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sentenceCount = count($sentences);
        $avgSentence = $sentenceCount > 0 ? round($wordCount / $sentenceCount, 1) : 0;
        $readabilityDetails = "avg sentence length {$avgSentence} words";

        $paragraphs = $this->paragraphWordCounts($content);
        $longestParagraph = $paragraphs === [] ? 0 : max($paragraphs);
        if ($avgSentence > 40) {
            $warnings[] = "Average sentence length is {$avgSentence} words; consider splitting long sentences.";
        }
        if ($longestParagraph > 250) {
            $warnings[] = "Longest paragraph has {$longestParagraph} words; consider splitting it into sections.";
        }
        $checks[] = ['name' => 'readability', 'passed' => true, 'details' => $readabilityDetails.", longest paragraph {$longestParagraph} words"];

        $passed = $violations === [];

        return QualityReport::query()->create([
            'blog_post_id' => $post->id,
            'ai_generation_id' => $generation?->id,
            'passed' => $passed,
            'violations' => $violations,
            'warnings' => $warnings,
            'checks' => $checks,
            'word_count' => $wordCount,
        ]);
    }

    /**
     * Collect internal links as absolute URLs.
     *
     * A link is internal when its hostname matches the configured
     * APP_URL host (scheme- and port-insensitive: http/https and optional
     * ports are equivalent), or one of the explicitly trusted extra hosts.
     * Site-relative paths always resolve against the configured APP_URL.
     * Unrelated external domains and unconfigured subdomains are never
     * treated as internal.
     */
    public function internalLinks(string $content, array $extraHosts = []): Collection
    {
        $hosts = collect([
            SiteUrl::host(),
            ...$extraHosts,
        ])->filter()->map(fn ($h) => strtolower((string) preg_replace('/^www\./i', '', (string) $h)))->unique()->values();

        preg_match_all('/<a\s[^>]*href=("|\')(.*?)\1/i', $content, $matches);

        return collect($matches[2] ?? [])
            ->filter(fn (string $href) => trim($href) !== '' && ! str_starts_with($href, '#') && ! preg_match('/^(mailto:|tel:)/i', $href))
            ->map(function (string $href) use ($hosts) {
                $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (str_starts_with($href, '/')) {
                    return SiteUrl::base().$href;
                }

                if (preg_match('/^https?:\/\//i', $href)) {
                    $host = strtolower((string) preg_replace('/^www\./i', '', (string) parse_url($href, PHP_URL_HOST)));

                    return $hosts->contains($host) ? $href : null;
                }

                return null;
            })
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * HTTP-validate one internal link. Anything that is not a 2xx/3xx —
     * including an unreachable host — fails, because an unverified link must
     * never be published.
     */
    protected function checkLink(string $url): array
    {
        try {
            $response = Http::timeout(10)->connectTimeout(5)->get($url);
            $status = $response->status();

            return ['ok' => $status < 400, 'status' => $status];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 'unreachable'];
        }
    }

    /**
     * Heuristic detection of statistical/research phrasing that could
     * indicate fabricated facts: percentages attached to claims about
     * people/studies, and "studies show"-style assertions. Plain marketing
     * phrasing like "100% free" is not flagged.
     *
     * @return list<string> matched sentence fragments
     */
    protected function findUnsupportedClaimPatterns(string $content): array
    {
        $text = trim(strip_tags($content));
        $patterns = [
            '/\b(?:studies|research|scientists|survey[s]?|statistics|a study)\s+(?:show[s]?|prove[s]?|found|suggest[s]?|says?|report[s]?)\b[^.!?]*/i',
            '/\baccording to (?:a|the|recent) (?:study|survey|report|research|analysis)\b[^.!?]*/i',
            '/\b\d{1,3}(?:\.\d+)?\s?%\s+(?:of|more|less|fewer|faster|slower|higher|lower|increase[sd]?|decrease[sd]?|reduction|likely|chance)\b[^.!?]*/i',
            '/\b(?:users|people|marketers|businesses|companies|consumers|readers)\b[^.!?]{0,80}?\b\d{1,3}(?:\.\d+)?\s?%/i',
            '/\b(?:grows?|grew|increase[sd]?|decrease[sd]?|drop(?:ped)?|rose|fell)\s+by\s+\d{1,3}(?:\.\d+)?\s?%/i',
        ];

        $matches = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $found, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($found as $match) {
                $matches[] = trim($match[0]);
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * @return list<int>
     */
    protected function paragraphWordCounts(string $content): array
    {
        preg_match_all('/<p[\s>](.*?)<\/p>/is', $content, $matches);

        return collect($matches[1] ?? [])
            ->map(fn ($p) => str_word_count(trim(strip_tags($p))))
            ->filter(fn ($count) => $count > 0)
            ->values()
            ->all();
    }

    protected function findTagImbalance(string $html): ?string
    {
        if (preg_match_all('/<(\s*\/?\s*)([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)(\s*\/?\s*)>/', $html, $matches, PREG_SET_ORDER) === false) {
            return null;
        }

        $stack = [];

        foreach ($matches as $match) {
            $name = strtolower($match[2]);
            $isClosing = trim($match[1]) !== '';
            $isSelfClosing = str_ends_with(trim($match[4]), '/');

            if (in_array($name, self::VOID_TAGS, true) || $isSelfClosing) {
                continue;
            }

            if ($isClosing) {
                if (($stack[array_key_last($stack)] ?? null) === $name) {
                    array_pop($stack);
                } elseif (in_array($name, $stack, true)) {
                    while ($stack !== []) {
                        if (array_pop($stack) === $name) {
                            break;
                        }
                    }
                } else {
                    return "stray closing </{$name}> tag";
                }
            } else {
                $stack[] = $name;
            }
        }

        if ($stack !== []) {
            return 'unclosed <'.end($stack).'> tag';
        }

        return null;
    }
}
