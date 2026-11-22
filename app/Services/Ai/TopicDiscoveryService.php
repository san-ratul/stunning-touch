<?php

namespace App\Services\Ai;

use App\Models\AutomationSetting;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\PipelineRun;
use App\Models\Tool;
use App\Services\Research\ResearchResult;
use App\Services\Research\ResearchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Discovers eligible blog topics by combining:
 * - admin-configured manual topics,
 * - RSS-derived topics (research service),
 * - external research discoveries (Wikipedia / HN / web search),
 * - content gaps (active tools and categories with no related posts),
 * - previously published articles (to avoid repeats).
 *
 * Candidates are deduplicated and screened through DuplicateDetectorService.
 * When every candidate is exhausted or duplicate, this service reports null
 * rather than silently reusing topics.
 */
class TopicDiscoveryService
{
    public function __construct(
        protected ResearchService $research,
        protected DuplicateDetectorService $duplicates,
    ) {}

    /**
     * Find the first eligible topic (passing duplicate checks), or null.
     *
     * Candidates are randomized (manual topics are shuffled) and any subject
     * already covered by a completed pipeline run or an existing AI article
     * is skipped, so the same topic is never generated twice while unused
     * topics remain.
     *
     * @return array{topic: string, source: string, meta: array}|null
     */
    public function findEligibleTopic(?AutomationSetting $automation = null, ?int $threshold = null): ?array
    {
        $automation ??= AutomationSetting::current();
        $used = $this->recentlyUsedSubjects();

        foreach ($this->discover($automation) as $candidate) {
            $topic = (string) $candidate['topic'];

            if ($used->contains($this->normalize($topic))) {
                continue;
            }

            $analysis = $this->duplicates->analyze(
                title: $topic,
                threshold: $threshold ?? $automation->duplicate_threshold ?? null,
            );

            if (! $analysis['duplicate']) {
                $candidate['meta']['duplicate_evaluated'] = $analysis['evaluated'];

                return ['topic' => $topic, 'source' => $candidate['source'], 'meta' => $candidate['meta']];
            }
        }

        return null;
    }

    /**
     * Subjects that already produced (or informed) an AI article: topics of
     * completed pipeline runs and titles of AI-generated posts. A generated
     * article's title usually differs from its configured topic, so both
     * sets are required to prevent near-duplicate rewrites of the same
     * subject.
     */
    protected function recentlyUsedSubjects(): Collection
    {
        $fromRuns = PipelineRun::query()
            ->where('status', PipelineRun::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->limit(200)
            ->pluck('topic');

        $fromPosts = BlogPost::query()
            ->where('source', 'ai')
            ->orderByDesc('id')
            ->limit(200)
            ->pluck('title');

        return $fromRuns->merge($fromPosts)
            ->map(fn ($subject) => $this->normalize((string) $subject))
            ->filter(fn ($subject) => $subject !== '')
            ->unique()
            ->values();
    }

    /**
     * Build ranked topic candidates.
     *
     * @return Collection<int, array{topic: string, source: string, meta: array}>
     */
    public function discover(?AutomationSetting $automation = null, ?int $limitPerSource = null): Collection
    {
        $automation ??= AutomationSetting::current();
        $limit = $limitPerSource ?? max(1, (int) ($automation->sources_per_source ?? 5));

        $candidates = collect();
        $seen = [];

        $push = function (string $topic, string $source, array $meta = []) use ($candidates, &$seen) {
            $topic = trim($topic);

            if (mb_strlen($topic) < 8 || mb_strlen($topic) > 400) {
                return;
            }

            $key = $this->normalize($topic);

            if ($key === '' || isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $candidates->push(['topic' => $topic, 'source' => $source, 'meta' => $meta]);
        };

        // 1. Admin-configured manual topics (highest intent), shuffled so
        // the list order never decides what gets generated — otherwise the
        // first topic would repeat until exhausted.
        foreach (collect((array) ($automation->topics ?? []))->filter()->shuffle()->values() as $topic) {
            $push((string) $topic, 'manual', []);
        }

        // 2. Content gaps: active tools with no related blog posts.
        foreach (collect($this->contentGaps())->shuffle()->values() as $topic) {
            $push((string) $topic, 'content_gap', []);
        }

        // 3. RSS-derived topics.
        if ($automation->research_enabled ?? true) {
            try {
                $this->research->fromRssFeeds($limit)->each(
                    fn (ResearchResult $r) => $push($r->title, 'rss', ['url' => $r->url])
                );
            } catch (\Throwable $e) {
                Log::info('Topic discovery: RSS source unavailable', ['error' => Str::limit($e->getMessage(), 160)]);
            }

            // 4. External research discoveries from seed keywords.
            foreach ($this->seedQueries($automation) as $seed) {
                try {
                    $this->research->fromWikipedia($seed, max(1, (int) ceil($limit / 2)))->each(
                        fn (ResearchResult $r) => $push($r->title, 'research', ['url' => $r->url, 'seed' => $seed])
                    );
                    $this->research->fromHackerNews($seed, max(1, (int) ceil($limit / 2)))->each(
                        fn (ResearchResult $r) => $push($r->title, 'research', ['url' => $r->url, 'seed' => $seed])
                    );
                } catch (\Throwable $e) {
                    Log::info('Topic discovery: research source unavailable', ['seed' => $seed, 'error' => Str::limit($e->getMessage(), 160)]);
                }
            }
        }

        // Deprioritize candidates that duplicate recent published article titles
        // wholesale (exact normalized match) — the duplicate detector will
        // still apply deeper checks at generation time.
        $recent = BlogPost::query()->latest('published_at')
            ->limit(50)
            ->get(['title'])
            ->map(fn ($p) => $this->normalize($p->title));

        return $candidates
            ->reject(fn ($candidate) => $recent->contains($this->normalize($candidate['topic'])))
            ->values();
    }

    /**
     * Active tools that no existing post focuses on — honest, site-derived
     * content-gap topics.
     *
     * @return list<string>
     */
    protected function contentGaps(): array
    {
        $topics = [];

        $tools = Tool::query()->active()->ordered()->limit(20)->get(['id', 'name', 'short_description']);

        foreach ($tools as $tool) {
            $hasCoverage = BlogPost::query()
                ->where('status', '!=', 'archived')
                ->where(function ($q) use ($tool) {
                    $q->where('focus_keyword', 'like', '%'.Str::lower($tool->name).'%')
                        ->orWhere('content', 'like', '%'.$tool->slug.'%');
                })
                ->exists();

            if (! $hasCoverage) {
                $topics[] = 'How to use the '.$tool->name.' step by step';
            }
        }

        // Categories without enough posts.
        BlogCategory::query()->where('is_active', true)->limit(10)->get(['id', 'name'])
            ->each(function (BlogCategory $category) use (&$topics) {
                $count = BlogPost::query()->where('blog_category_id', $category->id)->where('status', '!=', 'archived')->count();

                if ($count === 0) {
                    $topics[] = 'Getting started with '.$category->name;
                }
            });

        return array_slice($topics, 0, 10);
    }

    /**
     * @return list<string>
     */
    protected function seedQueries(AutomationSetting $automation): array
    {
        $seeds = [];

        foreach (preg_split('/[,;]+/', (string) ($automation->keywords ?? '')) ?: [] as $keyword) {
            if (filled(trim($keyword))) {
                $seeds[] = trim($keyword);
            }
        }

        if ($seeds === []) {
            Tool::query()->active()->ordered()->limit(3)->pluck('name')->each(fn ($name) => $seeds[] = $name.' online tools');
        }

        return array_slice(array_unique($seeds), 0, 3);
    }

    protected function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(trim($text))));
    }
}
