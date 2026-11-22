<?php

namespace App\Services\Research;

use App\Models\AiGeneration;
use App\Models\BlogPost;
use App\Models\BlogResearchSource;
use App\Models\ResearchRun;
use App\Models\ResearchSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Performs real external research for a topic across configured sources.
 *
 * Guarantees:
 * - Every URL and title originates from an actual provider response.
 * - Unavailable sources degrade gracefully and are recorded on the run.
 * - Results are deduplicated and optionally filtered by the admin's
 *   trusted-domain whitelist (admin-configured RSS feeds are trusted by
 *   definition of their configuration).
 * - No search volumes, statistics, or citations are ever invented.
 */
class ResearchService
{
    protected const HTTP_TIMEOUT = 15;

    protected const CONNECT_TIMEOUT = 5;

    public function settings(): ResearchSetting
    {
        return ResearchSetting::current();
    }

    /**
     * Research a topic and persist the run + source rows.
     *
     * @return array{run: ResearchRun, results: Collection<ResearchResult>}
     */
    public function researchForTopic(string $topic, ?AiGeneration $generation = null, ?int $limitPerSource = null): array
    {
        $settings = $this->settings();
        $limit = $limitPerSource ?? max(1, (int) ($settings->sources_per_source ?? 4));

        $run = ResearchRun::query()->create([
            'ai_generation_id' => $generation?->id,
            'topic' => Str::limit($topic, 500),
            'query' => Str::limit($topic, 500),
            'status' => 'completed',
        ]);

        if (! $settings->enabled) {
            $run->update(['status' => 'failed', 'errors' => ['Research is disabled in settings.']]);

            return ['run' => $run, 'results' => collect()];
        }

        $results = collect();
        $errors = [];

        // News-style topics must draw on recent stories, not years-old
        // hits: restrict Hacker News to a recent window, falling back to an
        // unfiltered search when the window yields nothing.
        $recentDays = $this->isNewsLikeTopic($topic) ? 90 : null;

        $fetchers = [
            'wikipedia' => fn () => $this->fromWikipedia($topic, $limit),
            'hackernews' => function () use ($topic, $limit, $recentDays) {
                $hits = $this->fromHackerNews($topic, $limit, $recentDays);

                if ($recentDays !== null && $hits->isEmpty()) {
                    $hits = $this->fromHackerNews($topic, $limit);
                }

                return $hits;
            },
            'rss' => fn () => $this->fromRssFeeds($limit),
            'web' => fn () => $this->fromWebSearch($topic, $limit),
        ];

        foreach ($fetchers as $type => $fetcher) {
            try {
                $results = $results->merge($fetcher());
            } catch (\Throwable $e) {
                $errors[] = ucfirst($type).' source failed: '.Str::limit($e->getMessage(), 200);
                Log::warning('Research source failed', ['type' => $type, 'error' => Str::limit($e->getMessage(), 200)]);
            }
        }

        $deduped = $this->deduplicate($this->sortByRelevance($results));

        $status = match (true) {
            $deduped->isNotEmpty() && $errors !== [] => 'partial',
            $deduped->isNotEmpty() => 'completed',
            default => 'failed',
        };

        $run->update([
            'status' => $status,
            'sources_found' => $deduped->count(),
            'errors' => $errors ?: null,
        ]);

        return ['run' => $run, 'results' => $deduped];
    }

    /**
     * Persist normalized results as source rows linked to a generation.
     */
    public function storeSources(Collection $results, string $query, ?AiGeneration $generation = null, ?ResearchRun $run = null): Collection
    {
        $rows = collect();

        foreach ($results as $result) {
            $rows->push(BlogResearchSource::query()->create([
                'ai_generation_id' => $generation?->id,
                'research_run_id' => $run?->id,
                'url' => substr((string) $result->url, 0, 500),
                'title' => substr((string) $result->title, 0, 500),
                'snippet' => $result->snippet !== '' ? Str::limit($result->snippet, 2000) : null,
                'source_type' => $result->sourceType,
                'query' => Str::limit($query, 500),
                'relevance_score' => $result->relevanceScore,
                'fetched_at' => $result->fetchedAt,
            ]));
        }

        return $rows;
    }

    /**
     * Link stored source rows to the article they informed.
     */
    public function attachSourcesToPost(Collection $sourceRows, BlogPost $post): void
    {
        $ids = $sourceRows->filter(fn ($row) => $row instanceof BlogResearchSource)->pluck('id')->all();

        if ($ids !== []) {
            BlogResearchSource::query()->whereIn('id', $ids)->update(['blog_post_id' => $post->id]);
        }
    }

    /**
     * Wikipedia REST API search. URLs are constructed from the API's own
     * page key (https://en.wikipedia.org/wiki/{key}) — never synthesized.
     */
    public function fromWikipedia(string $query, int $limit): Collection
    {
        $response = Http::timeout(self::HTTP_TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->withHeaders(['User-Agent' => 'StunningTouchBlogBot/1.0 (research)'])
            ->get('https://en.wikipedia.org/w/rest.php/v1/search/page', [
                'q' => $query,
                'limit' => $limit,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Wikipedia API error '.$response->status());
        }

        return collect($response->json('pages') ?? [])
            ->filter(fn ($page) => filled($page['key'] ?? null) && filled($page['title'] ?? null))
            ->map(fn ($page) => new ResearchResult(
                url: 'https://en.wikipedia.org/wiki/'.$page['key'],
                title: (string) $page['title'],
                snippet: $this->cleanSnippet($page['excerpt'] ?? ''),
                sourceType: 'wikipedia',
                fetchedAt: now()->toIso8601String(),
                meta: ['description' => $this->cleanSnippet($page['description'] ?? '')],
            ));
    }

    /**
     * Hacker News (Algolia) story search. Stories without an external URL
     * fall back to their Hacker News item URL (derived from the objectID).
     *
     * When $recentDays is given, only stories created within that window are
     * requested (numericFilters on created_at_i) — used for news-style
     * topics so articles never cite years-old stories as current news.
     */
    public function fromHackerNews(string $query, int $limit, ?int $recentDays = null): Collection
    {
        $params = [
            'query' => $query,
            'tags' => 'story',
            'hitsPerPage' => $limit,
        ];

        if ($recentDays !== null && $recentDays > 0) {
            $params['numericFilters'] = 'created_at_i>'.now()->subDays($recentDays)->timestamp;
        }

        $response = Http::timeout(self::HTTP_TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->get('https://hn.algolia.com/api/v1/search', $params);

        if ($response->failed()) {
            throw new \RuntimeException('Hacker News API error '.$response->status());
        }

        return collect($response->json('hits') ?? [])
            ->filter(fn ($hit) => filled($hit['title'] ?? null))
            ->map(function ($hit) {
                $url = filled($hit['url'] ?? null)
                    ? (string) $hit['url']
                    : 'https://news.ycombinator.com/item?id='.($hit['objectID'] ?? '');

                return new ResearchResult(
                    url: $url,
                    title: (string) $hit['title'],
                    snippet: $this->cleanSnippet($hit['story_text'] ?? $hit['comment_text'] ?? $hit['_highlightResult']['title']['value'] ?? ''),
                    sourceType: 'hackernews',
                    fetchedAt: now()->toIso8601String(),
                    meta: [
                        'points' => $hit['points'] ?? null,
                        'author' => $hit['author'] ?? null,
                        'created_at' => $hit['created_at'] ?? null,
                    ],
                );
            })
            ->filter(fn (ResearchResult $r) => filled($r->url));
    }

    /**
     * Fetch configured RSS feeds (admin-curated; trusted by configuration).
     * Supports RSS 2.0 <item> and Atom <entry> shapes.
     */
    public function fromRssFeeds(int $limit): Collection
    {
        $feeds = array_slice(array_filter((array) ($this->settings()->rss_feeds ?? [])), 0, 10);

        if ($feeds === []) {
            return collect();
        }

        $results = collect();

        foreach ($feeds as $feedUrl) {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->get((string) $feedUrl);

            if ($response->failed()) {
                throw new \RuntimeException('RSS feed failed ('.Str::limit((string) $feedUrl, 120).'): HTTP '.$response->status());
            }

            $xml = @simplexml_load_string($response->body());

            if ($xml === false) {
                throw new \RuntimeException('RSS feed returned invalid XML ('.Str::limit((string) $feedUrl, 120).').');
            }

            $items = isset($xml->channel->item) ? $xml->channel->item : (isset($xml->entry) ? $xml->entry : null);

            if ($items === null) {
                continue;
            }

            foreach ($items as $item) {
                if ($results->count() >= $limit) {
                    break 2;
                }

                $link = (string) ($item->link ?? ($item->link['href'] ?? ''));

                if (! filled($link) || ! filled((string) $item->title)) {
                    continue;
                }

                $results->push(new ResearchResult(
                    url: $link,
                    title: (string) $item->title,
                    snippet: $this->cleanSnippet((string) ($item->description ?? $item->summary ?? '')),
                    sourceType: 'rss',
                    fetchedAt: now()->toIso8601String(),
                    meta: ['feed' => (string) $feedUrl, 'published' => (string) ($item->pubDate ?? $item->updated ?? '')],
                ));
            }
        }

        return $results;
    }

    /**
     * Configurable external web search. Requires the admin to configure a
     * provider and API key; no results are invented when unavailable.
     */
    public function fromWebSearch(string $query, int $limit): Collection
    {
        $settings = $this->settings();
        $provider = (string) ($settings->provider ?? 'none');

        if ($provider === 'none') {
            return collect();
        }

        if (blank($settings->api_key)) {
            throw new \RuntimeException("Web search provider \"{$provider}\" is configured without an API key.");
        }

        return match ($provider) {
            'tavily' => $this->fromTavily($query, $limit),
            'serpapi' => $this->fromSerpApi($query, $limit),
            default => throw new \RuntimeException("Unknown web search provider \"{$provider}\"."),
        };
    }

    protected function fromTavily(string $query, int $limit): Collection
    {
        $response = Http::timeout(self::HTTP_TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->post('https://api.tavily.com/search', [
                'api_key' => $this->settings()->api_key,
                'query' => $query,
                'max_results' => $limit,
                'search_depth' => 'basic',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Tavily API error '.$response->status());
        }

        return collect($response->json('results') ?? [])
            ->filter(fn ($r) => filled($r['url'] ?? null) && filled($r['title'] ?? null))
            ->map(fn ($r) => new ResearchResult(
                url: (string) $r['url'],
                title: (string) $r['title'],
                snippet: $this->cleanSnippet($r['content'] ?? ''),
                sourceType: 'web',
                fetchedAt: now()->toIso8601String(),
                meta: ['provider' => 'tavily', 'score' => $r['score'] ?? null],
            ));
    }

    protected function fromSerpApi(string $query, int $limit): Collection
    {
        $response = Http::timeout(self::HTTP_TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->get('https://serpapi.com/search.json', [
                'engine' => 'google',
                'q' => $query,
                'api_key' => $this->settings()->api_key,
                'num' => $limit,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('SerpApi error '.$response->status());
        }

        return collect($response->json('organic_results') ?? [])
            ->filter(fn ($r) => filled($r['link'] ?? null) && filled($r['title'] ?? null))
            ->map(fn ($r) => new ResearchResult(
                url: (string) $r['link'],
                title: (string) $r['title'],
                snippet: $this->cleanSnippet($r['snippet'] ?? ''),
                sourceType: 'web',
                fetchedAt: now()->toIso8601String(),
                meta: ['provider' => 'serpapi', 'position' => $r['position'] ?? null],
            ));
    }

    /**
     * Keep only results whose domain is on the admin trusted-domain list.
     * An empty list means all domains are accepted. Admin-configured RSS
     * feeds bypass the filter (they are curated by configuration).
     */
    public function filterByTrustedDomains(Collection $results): Collection
    {
        $trusted = array_values(array_filter(array_map(
            fn ($d) => rtrim(Str::lower(trim((string) $d)), '.'),
            (array) ($this->settings()->trusted_domains ?? [])
        )));

        if ($trusted === []) {
            return $results;
        }

        return $results->filter(function (ResearchResult $result) use ($trusted) {
            if ($result->sourceType === 'rss') {
                return true;
            }

            $host = parse_url($result->url, PHP_URL_HOST);

            if (! is_string($host)) {
                return false;
            }

            $host = preg_replace('/^www\./', '', Str::lower($host));

            return collect($trusted)->contains(
                fn ($domain) => $host === $domain || str_ends_with((string) $host, '.'.$domain)
            );
        })->values();
    }

    /**
     * Topics about news, updates, or trends must draw on recent sources.
     * Evergreen how-to topics are intentionally not matched — their best
     * research may legitimately be older.
     */
    public function isNewsLikeTopic(string $topic): bool
    {
        return (bool) preg_match(
            '/\b(latest|news|updates?|trends?|new(est)?|recent\w*|launch\w*|releas\w*|announc\w*|developments?|innovations?|breaking|this (?:week|month|year))\b/i',
            $topic,
        );
    }

    /**
     * Local relevance heuristic: how many query terms appear in the
     * title/snippet. Computed — never provider-fabricated.
     */
    public function scoreRelevance(Collection $results, string $query): Collection
    {
        $terms = collect(preg_split('/\s+/', Str::lower($query) ?: ''))
            ->filter(fn ($t) => mb_strlen($t) >= 3)
            ->unique()
            ->values();

        if ($terms->isEmpty()) {
            return $results;
        }

        return $results->map(function (ResearchResult $result) use ($terms) {
            $haystack = Str::lower($result->title.' '.$result->snippet);
            $score = $terms->reduce(fn ($carry, $term) => $carry + (str_contains($haystack, (string) $term) ? 1 : 0), 0);

            return new ResearchResult(
                url: $result->url,
                title: $result->title,
                snippet: $result->snippet,
                sourceType: $result->sourceType,
                fetchedAt: $result->fetchedAt,
                relevanceScore: min(100, (int) round(($score / max(1, $terms->count())) * 100)),
                meta: $result->meta,
            );
        });
    }

    protected function sortByRelevance(Collection $results): Collection
    {
        return $results->sortByDesc(fn (ResearchResult $r) => $r->relevanceScore ?? 0)->values();
    }

    protected function deduplicate(Collection $results): Collection
    {
        $seen = [];

        return $results->reject(function (ResearchResult $result) use (&$seen) {
            $key = $this->normalizeUrl($result->url);

            if ($key === '' || isset($seen[$key])) {
                return true;
            }

            $seen[$key] = true;

            return false;
        })->values();
    }

    protected function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'])) {
            return Str::lower(trim($url));
        }

        $host = preg_replace('/^www\./', '', Str::lower($parts['host']));
        $path = rtrim(Str::lower($parts['path'] ?? ''), '/');

        return $host.$path;
    }

    protected function cleanSnippet(?string $html): string
    {
        $text = trim(strip_tags((string) $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        return Str::limit(trim($text), 600);
    }
}
