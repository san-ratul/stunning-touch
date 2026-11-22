<?php

namespace App\Services\Ai;

use App\Models\AiGeneration;
use App\Models\AutomationSetting;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\ResearchRun;
use App\Models\Tool;
use App\Services\Research\ResearchResult;
use App\Services\Research\ResearchService;
use App\Support\SiteUrl;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generates SEO blog drafts with AI assistance.
 *
 * Workflow: topic selection -> outline+keywords -> article -> metadata ->
 * draft saved for admin review. Content generated without a verified
 * search-data provider is clearly marked as AI-suggested (source=ai).
 * Drafts are NEVER auto-published unless the admin explicitly enabled
 * auto-publish AND the article passes quality validation; generation
 * failures never leave partial posts. When research context is supplied,
 * the AI is instructed to use only that evidence for factual claims, and
 * source provenance is persisted on the article.
 */
class BlogGeneratorService
{
    public function __construct(
        protected AiProviderService $ai,
        protected QualityValidatorService $validator,
        protected ResearchService $research,
    ) {}

    public function generateFromAutomationSettings(bool $manual = false): array
    {
        $automation = AutomationSetting::current();

        if (! $manual && ! $automation->is_enabled) {
            return ['success' => false, 'message' => 'Automation is disabled.'];
        }

        $topic = $this->pickTopic($automation);

        if ($topic === null) {
            return ['success' => false, 'message' => 'No topics configured. Add preferred topics in Automation Settings.'];
        }

        return $this->generateForTopic($topic, $automation);
    }

    /**
     * Generate an article for a topic.
     *
     * @param  array{
     *     research?: Collection|array,
     *     research_run_id?: int|null,
     *     focus_keyword?: string|null,
     *     secondary_keywords?: array,
     *     min_word_count?: int,
     *     max_word_count?: int,
     *     target_word_count?: int|null,
     *     instructions?: string|null,
     *     preferred_category?: string|null,
     * }  $context
     */
    public function generateForTopic(string $topic, ?AutomationSetting $automation = null, array $context = []): array
    {
        $automation ??= AutomationSetting::current();

        if (! $this->ai->isConfigured()) {
            return ['success' => false, 'message' => 'AI provider is not configured or enabled.'];
        }

        $generation = AiGeneration::query()->create(['topic' => Str::limit($topic, 400), 'status' => 'pending']);

        try {
            $tools = Tool::query()->active()->ordered()->get(['id', 'name', 'slug', 'short_description']);
            $categories = BlogCategory::query()->orderBy('name')->get(['id', 'name']);

            $researchResults = $this->normalizeResearch($context['research'] ?? null);

            // Persist provenance: every research finding that informed this
            // generation is stored with its URL and fetch timestamp.
            $sourceRows = collect();
            if ($researchResults->isNotEmpty()) {
                $sourceRows = $this->research->storeSources(
                    $researchResults,
                    $topic,
                    $generation,
                    isset($context['research_run_id']) ? ResearchRun::query()->find($context['research_run_id']) : null
                );
            }

            $prompt = $this->buildPrompt($topic, $automation, $tools, $categories, $researchResults, $context);

            // Sequential LLM execution: heavy generation holds the shared
            // generation lock so only one model request runs at a time on
            // memory-constrained hosts. The whole sequence — initial draft
            // plus any bounded continuation calls — runs under one lock.
            $outcome = $this->ai->withGenerationLock(function () use ($topic, $automation, $prompt, $context) {
                $systemMessage = 'You are an expert SEO content writer for an online tools website. You write original, accurate, helpful English articles. When research extracts are provided you may only state factual claims supported by them; never invent statistics, quotes, or sources beyond the supplied evidence. You respond with valid JSON only.';

                $response = $this->ai->chatWithUsage([
                    ['role' => 'system', 'content' => $systemMessage],
                    ['role' => 'user', 'content' => $prompt],
                ], maxTokens: $this->generationMaxTokens());

                if ($response === null) {
                    return null;
                }

                if (blank($response['content'])) {
                    throw new \RuntimeException('AI returned an empty response.');
                }

                $data = $this->parseJson($response['content']);

                $post = $this->createDraft($data, $topic, $automation);

                $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
                $this->accumulateUsage($usage, $response['usage'] ?? null);

                // Bounded continuation: when the first draft is shorter than
                // the configured minimum, ask for additional sections using
                // the same provider, still inside the generation lock. The
                // number of extra calls is admin-configurable and capped.
                $minWords = $this->minimumWordCount($automation, $context);
                $continuationsLeft = max(0, (int) ($automation->max_generation_continuations ?? 2));

                while ($this->wordCount((string) $post->content) < $minWords && $continuationsLeft > 0) {
                    $continuationsLeft--;

                    $continuation = $this->requestContinuation($post, $topic, $minWords, $context);
                    $this->accumulateUsage($usage, $continuation['usage'] ?? null);

                    if (blank($continuation['content'] ?? null)) {
                        break;
                    }

                    $addedHtml = $this->parseContinuation((string) $continuation['content']);

                    if (trim($addedHtml) === '') {
                        // The model found nothing of value to add — do not
                        // fabricate content just to reach the number.
                        break;
                    }

                    $content = (string) $post->content."\n".trim($addedHtml);
                    $post->update([
                        'content' => $content,
                        'reading_time' => BlogPost::readingTimeFor($content),
                    ]);
                }

                return ['post' => $post, 'usage' => $usage];
            });

            if ($outcome === null) {
                throw new \RuntimeException('Another AI generation is already in progress; try again shortly.');
            }

            $post = $outcome['post'];

            $this->research->attachSourcesToPost($sourceRows, $post);

            $report = $this->validator->validate($post, $generation, [
                'min_word_count' => $context['min_word_count'] ?? null,
                'max_word_count' => $context['max_word_count'] ?? null,
                'duplicate_threshold' => $context['duplicate_threshold'] ?? null,
                'research_sources' => $sourceRows,
            ]);

            // Auto-publish is opt-in AND gated on quality validation. A
            // failing report always leaves the article as a draft.
            $autoPublished = false;
            if ($automation->auto_publish && $report->passed) {
                $post->update(['status' => 'published', 'published_at' => now()]);
                $autoPublished = true;
            }

            $generation->update([
                'status' => 'completed',
                'blog_post_id' => $post->id,
                'prompt_tokens' => $outcome['usage']['prompt_tokens'] > 0 ? $outcome['usage']['prompt_tokens'] : null,
                'completion_tokens' => $outcome['usage']['completion_tokens'] > 0 ? $outcome['usage']['completion_tokens'] : null,
            ]);

            $message = match (true) {
                $autoPublished => 'Published "'.$post->title.'": passed all automated quality checks.',
                (bool) $automation->auto_publish => 'Draft created: "'.$post->title.'". Auto-publish blocked by quality validation: '.Str::limit($report->violations[0] ?? 'unknown issue', 160),
                ! $report->passed => 'Draft created: "'.$post->title.'". Saved as a draft — quality validation failed: '.Str::limit($report->violations[0] ?? 'unknown issue', 160),
                default => 'Draft created: "'.$post->title.'" is ready for review.',
            };

            return ['success' => true, 'message' => $message, 'post_id' => $post->id];
        } catch (\Throwable $e) {
            $generation->update(['status' => 'failed', 'error_message' => Str::limit($e->getMessage(), 1000)]);
            Log::error('AI blog generation failed: '.$e->getMessage());

            return ['success' => false, 'message' => 'Generation failed: '.Str::limit($e->getMessage(), 200)];
        }
    }

    /**
     * One bounded continuation request for a too-short draft.
     *
     * @param  array{target_word_count?: int|null}  $context
     * @return array{content: string|null, usage: array|null}
     */
    protected function requestContinuation(BlogPost $post, string $topic, int $minWords, array $context): array
    {
        try {
            return $this->ai->chatWithUsage([
                ['role' => 'system', 'content' => 'You are an expert SEO content writer for an online tools website. You write original, accurate, helpful English articles. You never repeat existing content and never pad with filler. You respond with valid JSON only.'],
                ['role' => 'user', 'content' => $this->buildContinuationPrompt($post, $topic, $minWords, $context['target_word_count'] ?? null)],
            ], maxTokens: $this->generationMaxTokens());
        } catch (\Throwable $e) {
            // A continuation failure must not destroy the valid (if short)
            // first draft: stop extending and let quality validation report
            // the shortfall honestly.
            Log::warning('AI continuation call failed: '.Str::limit($e->getMessage(), 200));

            return ['content' => null, 'usage' => null];
        }
    }

    /**
     * @param  array{target_word_count?: int|null}  $context
     */
    protected function buildContinuationPrompt(BlogPost $post, string $topic, int $minWords, ?int $targetWords): string
    {
        $current = Str::limit((string) $post->content, 12000);
        $missing = max(0, $minWords - $this->wordCount((string) $post->content));
        $lengthContext = $targetWords !== null
            ? "The target length is approximately {$targetWords} words"
            : "The minimum length is {$minWords} words";

        return <<<PROMPT
The blog article "{$topic}" is too short for publication. It currently has fewer than the required {$minWords} words (roughly {$missing} more words are needed). {$lengthContext}.

Current draft (for context only — do not repeat any of it):
{$current}

Write the continuation of this article: 2-4 NEW sections introduced by h2 headings, each with substantive paragraphs and/or lists. Continue naturally with material the draft has not covered yet — practical step-by-step guidance, tool walkthroughs, comparisons, troubleshooting, best practices, or FAQ-style sections.

Strict rules:
- Do NOT repeat, restate, or rephrase anything already present in the current draft.
- Every sentence must add real, accurate, useful information. Never pad with filler or empty generalities just to reach a word count.
- Keep the same clean HTML style (h2, h3, p, ul, li). Start with an h2 heading. No h1.
- If there is genuinely nothing of value left to add, return an empty content_html string instead of inventing filler.

Respond with ONLY valid JSON (no markdown fences) in this exact shape:
{"content_html": "..."}
PROMPT;
    }

    protected function parseContinuation(string $raw): string
    {
        $raw = trim($raw);

        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $raw);
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return '';
        }

        return is_string($decoded['content_html'] ?? null) ? $decoded['content_html'] : '';
    }

    /**
     * Output budget for generation calls: the admin-configured "Max output
     * tokens" when set (so reasoning models like GPT-OSS can be given a
     * larger budget), otherwise a safe 6000 default.
     */
    protected function generationMaxTokens(): int
    {
        return (int) ($this->ai->settings()?->max_tokens ?? 0) ?: 6000;
    }

    protected function minimumWordCount(AutomationSetting $automation, array $context): int
    {
        return max(1, (int) ($context['min_word_count'] ?? $automation->min_word_count ?: 900));
    }

    protected function wordCount(string $html): int
    {
        $text = trim(strip_tags($html));

        return $text === '' ? 0 : str_word_count($text);
    }

    /**
     * Sum token usage across all generation calls (initial + continuations)
     * so the persisted accounting always reflects the real total.
     */
    protected function accumulateUsage(array &$total, ?array $usage): void
    {
        if ($usage === null) {
            return;
        }

        foreach (['prompt_tokens', 'completion_tokens'] as $key) {
            if (isset($usage[$key]) && is_numeric($usage[$key])) {
                $total[$key] += (int) $usage[$key];
            }
        }
    }

    /**
     * @return Collection<ResearchResult>
     */
    protected function normalizeResearch(mixed $research): Collection
    {
        if (blank($research)) {
            return collect();
        }

        return collect($research)
            ->map(fn ($item) => $item instanceof ResearchResult ? $item : ResearchResult::fromArray((array) $item))
            ->values();
    }

    protected function pickTopic(AutomationSetting $automation): ?string
    {
        // Random order: the admin list is a pool, not a priority queue —
        // always taking the first entry would regenerate the same subject.
        $topics = collect($automation->topics ?? [])->filter()->shuffle()->values();

        if ($topics->isEmpty()) {
            return null;
        }

        // Avoid repeating recent article subjects (normalized comparison:
        // the generated title usually differs from the configured topic).
        $recent = BlogPost::query()->where('source', 'ai')->orderByDesc('created_at')->limit(20)->pluck('title')
            ->map(fn ($title) => trim((string) preg_replace('/\s+/', ' ', Str::lower(trim((string) $title)))));

        $normalizedTopics = $topics->mapWithKeys(fn ($topic) => [$this->normalizeTopic((string) $topic) => $topic]);

        $unused = $normalizedTopics->reject(fn ($topic, $normalized) => $recent->contains($normalized));

        return ($unused->isNotEmpty() ? $unused : $normalizedTopics)->first();
    }

    protected function normalizeTopic(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(trim($text))));
    }

    protected function buildPrompt(string $topic, AutomationSetting $automation, $tools, $categories, Collection $researchResults, array $context = []): string
    {
        $keywords = $automation->keywords ?: '';
        $categoryIds = $automation->categories ?? [];
        $categoryNames = $categories->whereIn('id', $categoryIds)->pluck('name')->implode(', ') ?: $categories->pluck('name')->implode(', ');

        $toolList = $tools->map(fn ($t) => "- {$t->name} (slug: {$t->slug}, {$t->short_description})")->implode("\n");

        // Word-count instruction: the admin-selected target (when present)
        // is the primary instruction; otherwise the configured bounds apply.
        // The minimum is never negotiable — shorter articles fail validation.
        $minWords = $this->minimumWordCount($automation, $context);
        $maxWords = max($minWords, (int) ($context['max_word_count'] ?? $automation->max_word_count ?: 2000));
        $targetWords = filled($context['target_word_count'] ?? null) ? (int) $context['target_word_count'] : null;

        $lengthRequirement = $targetWords !== null
            ? "- Write approximately {$targetWords} words of ORIGINAL, accurate, genuinely helpful English content. Never stop below {$minWords} words: a shorter article fails quality validation and must be extended. Reach the full requested length before finishing."
            : "- Write {$minWords}-{$maxWords} words of ORIGINAL, accurate, genuinely helpful English content. Never stop below {$minWords} words: a shorter article fails quality validation and must be extended.";

        $researchSection = '';
        if ($researchResults->isNotEmpty()) {
            $extracts = $researchResults
                ->take(8)
                ->map(function (ResearchResult $r, int $i) {
                    $snippet = filled($r->snippet) ? "\n    {$r->snippet}" : '';
                    $stamp = $this->sourceDateLabel($r);

                    return '['.($i + 1)."] ({$r->sourceType}{$stamp}) \"{$r->title}\" — {$r->url}{$snippet}";
                })
                ->implode("\n");

            $researchSection = <<<RESEARCH_BLOCK

RESEARCH EXTRACTS (collected {$researchResults->count()} sources):
{$extracts}

Research rules (mandatory):
- Base every factual claim on the extracts above. If the evidence is insufficient, keep statements general and clearly advisory instead of inventing specifics.
- Never fabricate statistics, quotations, dates, or citations. Do not invent sources beyond those listed.
- You may reference these sources with natural wording; do not invent numbers beyond what the extracts state.
RESEARCH_BLOCK;
        }

        $keywordSection = '';
        if (filled($context['focus_keyword'] ?? null)) {
            $secondary = implode(', ', array_slice((array) ($context['secondary_keywords'] ?? []), 0, 8));
            $keywordSection = "\nKeyword guidance: focus keyword \"{$context['focus_keyword']}\"".($secondary !== '' ? "; secondary keywords: {$secondary}" : '').'.';
        }

        // Manual-generation extras (admin form): writing instructions,
        // target length guidance and a preferred category.
        $adminSection = '';
        if (filled($context['instructions'] ?? null)) {
            $adminSection .= "\nAdditional instructions from the editor: ".Str::limit((string) $context['instructions'], 600);
        }
        if (filled($context['target_word_count'] ?? null)) {
            $adminSection .= "\nTarget length: approximately ".(int) $context['target_word_count'].' words (never below the minimum).';
        }
        if (filled($context['preferred_category'] ?? null)) {
            $adminSection .= "\nPreferred blog category: ".Str::limit((string) $context['preferred_category'], 80).'.';
        }

        $siteUrl = SiteUrl::base();
        $today = now($automation->timezone ?: config('app.timezone'));
        $todayLine = 'Today is '.$today->format('l, F j, Y').'.';
        $monthYear = $today->format('F Y');

        return <<<PROMPT
Write a complete SEO-friendly blog article for the topic: "{$topic}"
{$todayLine}{$researchSection}{$keywordSection}{$adminSection}

Site context: Stunning Touch ({$siteUrl}) is a free online tools platform with the tagline "Your Everyday Tools, All in One Place.". Blog categories available: {$categoryNames}. Keyword preferences: {$keywords}

Available tools for internal linking (choose the most relevant 1-4, only if genuinely related):
{$toolList}

Requirements:
{$lengthRequirement}
- Write from the perspective of {$today->format('F j, Y')}. NEVER present older releases, model versions, prices, rankings, or events as if they are current.
- If the topic involves news, latest updates, or trends, cover ONLY the most recent developments (latest launches and improvements). Base them on the newest research extracts and prefer precise wording like "as of {$monthYear}".
- For news-style topics ignore clearly outdated extracts (older years, past-year roundups); use them as background at most, never as current facts. Never describe past years as the present.
- NEVER fabricate statistics, research findings, quotes, or search volumes. General advice and verifiable facts only.
- Use clean HTML content: h2/h3 headings, paragraphs, ul/li lists. Start directly with an introduction paragraph (no h1).
- Include 2-4 natural internal links to relevant tools chosen ONLY from the list above, using absolute URLs of the exact form {$siteUrl}/{tool-slug} (tool slugs come from the list above). Every article must contain at least 2 internal links. NEVER invent URLs or link to pages that do not exist on the site.
- Suggest honest, non-clickbait SEO metadata.

Respond with ONLY valid JSON (no markdown fences) in this exact shape:
{
  "title": "...",
  "slug": "kebab-case-slug",
  "excerpt": "one-two sentence summary",
  "content_html": "...",
  "focus_keyword": "...",
  "secondary_keywords": ["...", "..."],
  "seo_title": "max 60 chars",
  "meta_description": "max 155 chars",
  "blog_category": "one of: {$categoryNames}",
  "tags": ["tag1", "tag2"],
  "related_tools": ["tool-slug", "..."],
  "suggested_image_alt": "alt text for a future featured image",
  "outline": ["h2 item 1", "..."]
}
PROMPT;
    }

    /**
     * Human-readable publication date for a research extract, when the
     * provider supplied one — so the model can judge source freshness.
     */
    protected function sourceDateLabel(ResearchResult $result): string
    {
        $raw = (string) ($result->meta['created_at'] ?? $result->meta['published'] ?? '');

        if (blank($raw)) {
            return '';
        }

        try {
            $date = Carbon::parse($raw);
        } catch (\Throwable) {
            return '';
        }

        return ', '.$date->format('M Y');
    }

    protected function parseJson(string $raw): array
    {
        $raw = trim($raw);

        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $raw);
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded) || blank($decoded['title'] ?? null) || blank($decoded['content_html'] ?? null)) {
            throw new \RuntimeException('AI response was not valid article JSON.');
        }

        return $decoded;
    }

    protected function createDraft(array $data, string $topic, AutomationSetting $automation): BlogPost
    {
        $slug = Str::slug((string) ($data['slug'] ?? $data['title']));

        if (BlogPost::query()->where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(5));
        }

        $categoryId = BlogCategory::query()
            ->where('name', $data['blog_category'] ?? '')
            ->value('id')
            ?: ($automation->categories[0] ?? BlogCategory::query()->value('id'));

        $post = BlogPost::create([
            'blog_category_id' => $categoryId,
            'user_id' => null,
            'title' => Str::limit((string) $data['title'], 240),
            'slug' => $slug,
            'excerpt' => Str::limit((string) ($data['excerpt'] ?? ''), 900),
            'content' => (string) $data['content_html'],
            'focus_keyword' => (string) ($data['focus_keyword'] ?? ''),
            'secondary_keywords' => implode(', ', array_slice((array) ($data['secondary_keywords'] ?? []), 0, 6)),
            'seo_title' => Str::limit((string) ($data['seo_title'] ?? $data['title']), 250),
            'meta_description' => Str::limit((string) ($data['meta_description'] ?? $data['excerpt'] ?? ''), 500),
            // Drafts are always stored as drafts; auto-publish happens only
            // after quality validation passes (see generateForTopic).
            'status' => 'draft',
            'source' => 'ai',
            'reading_time' => BlogPost::readingTimeFor((string) $data['content_html']),
            'scheduled_at' => null,
            'published_at' => null,
        ]);

        // Featured image is never invented: admin uploads/chooses one during review.
        $tagIds = collect((array) ($data['tags'] ?? []))
            ->take(6)
            ->filter(fn ($t) => is_string($t) && filled($t))
            ->map(fn ($t) => BlogTag::firstOrCreate(['slug' => Str::slug($t)], ['name' => $t])->id)
            ->all();

        $post->tags()->sync($tagIds);

        return $post;
    }
}
