<?php

namespace App\Services\Ai;

use App\Models\AiGeneration;
use App\Models\AuditLog;
use App\Models\AutomationSetting;
use App\Models\BlogPost;
use App\Models\PipelineRun;
use App\Services\Research\ResearchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Central autonomous blogging pipeline.
 *
 * One invocation performs a bounded amount of work: at most one article
 * attempt for one due publishing slot. Progress is checkpointed on the
 * PipelineRun row (status/step/completed_steps), so interrupted runs are
 * safely resumed by the next scheduler tick. All AI work runs sequentially
 * behind the shared generation lock — no persistent queue workers needed.
 *
 * Publishing happens only when: auto_publish is enabled, quality validation
 * passed, research requirements passed, and duplicate checks passed. All of
 * those gates live in BlogGeneratorService/QualityValidatorService; the
 * pipeline adds the slot and limit logic around them.
 */
class BlogAutoPipeline
{
    protected const MAX_ATTEMPTS_PER_SLOT = 3;

    protected const MAX_POSTS_PER_DAY = 4;

    protected const DEFAULT_SLOTS = ['06:00', '12:00', '18:00', '21:00'];

    public function __construct(
        protected TopicDiscoveryService $discovery,
        protected ResearchService $research,
        protected KeywordService $keywords,
        protected BlogGeneratorService $generator,
        protected ImageService $images,
    ) {}

    /**
     * Run one bounded pipeline attempt.
     *
     * @return PipelineRun|null null when there was nothing to do (disabled,
     *                          no due slot, daily limit reached, busy lock).
     */
    public function run(?\DateTimeInterface $now = null): ?PipelineRun
    {
        $automation = AutomationSetting::current();

        if (! $automation->is_enabled) {
            return null;
        }

        $now = $now ? Carbon::parse($now) : now();
        $slot = $this->dueSlot($automation, $now);

        if ($slot === null) {
            return null;
        }

        return $this->executeSlot($automation, $slot, $now->copy());
    }

    /**
     * Determine the earliest due, unfulfilled slot with retries remaining.
     */
    public function dueSlot(AutomationSetting $automation, Carbon $now): ?string
    {
        $timezone = new \DateTimeZone($automation->timezone ?: config('app.timezone'));
        $localNow = $now->copy()->setTimezone($timezone);
        $today = $localNow->toDateString();

        $slots = collect((array) ($automation->publish_slots ?: self::DEFAULT_SLOTS))
            ->filter(fn ($slot) => preg_match('/^\d{2}:\d{2}$/', (string) $slot))
            ->sort()
            ->values();

        foreach ($slots as $slot) {
            if ($localNow->format('H:i') < $slot) {
                break; // slots are sorted; the rest are in the future
            }

            $fulfilled = PipelineRun::query()
                ->whereDate('slot_date', $today)
                ->where('slot', $slot)
                ->where('status', PipelineRun::STATUS_COMPLETED)
                ->exists();

            if ($fulfilled) {
                continue;
            }

            $attempts = PipelineRun::query()
                ->whereDate('slot_date', $today)
                ->where('slot', $slot)
                ->count();

            if ($attempts >= self::MAX_ATTEMPTS_PER_SLOT) {
                Log::warning('Pipeline slot exhausted retries', ['slot' => $slot, 'date' => $today]);

                continue;
            }

            return (string) $slot;
        }

        return null;
    }

    /**
     * Daily limit from settings, hard-capped at the safe maximum.
     */
    public function postsPerDay(AutomationSetting $automation): int
    {
        return min(self::MAX_POSTS_PER_DAY, max(1, (int) ($automation->posts_per_day ?: 1)));
    }

    protected function executeSlot(AutomationSetting $automation, string $slot, Carbon $now): ?PipelineRun
    {
        $timezone = new \DateTimeZone($automation->timezone ?: config('app.timezone'));
        $today = $now->copy()->setTimezone($timezone)->toDateString();

        $completedToday = PipelineRun::query()
            ->whereDate('slot_date', $today)
            ->where('status', PipelineRun::STATUS_COMPLETED)
            ->count();

        if ($completedToday >= $this->postsPerDay($automation)) {
            return null;
        }

        $previousAttempts = PipelineRun::query()
            ->whereDate('slot_date', $today)
            ->where('slot', $slot)
            ->count();

        $run = PipelineRun::query()->create([
            'slot_date' => $today,
            'slot' => $slot,
            'status' => PipelineRun::STATUS_RUNNING,
            'step' => 'topic_discovery',
            'completed_steps' => [],
            'attempts' => $previousAttempts + 1,
            'started_at' => $now,
        ]);

        try {
            // 1. Topic discovery + duplicate screening.
            $candidate = $this->discovery->findEligibleTopic($automation, $automation->duplicate_threshold);

            if ($candidate === null) {
                return $this->failRun($run, 'No eligible topic found: the topic list is exhausted or every candidate duplicates existing content.');
            }

            $topic = $candidate['topic'];
            $run->update(['topic' => Str::limit($topic, 500), 'topic_source' => $candidate['source']]);
            $run->markStep('topic_selected');

            // 2. Research (mandatory when research is enabled).
            $researchResults = collect();
            if ($automation->research_enabled) {
                $run->update(['step' => 'research']);
                $outcome = $this->research->researchForTopic($topic);

                $run->update(['research_run_id' => $outcome['run']->id]);
                $researchResults = $outcome['results'];

                if ($researchResults->isEmpty()) {
                    return $this->failRun($run, 'Research requirement failed: no usable sources were collected for this topic.');
                }
            }
            $run->markStep('research');

            // 3. Keyword analysis.
            $run->update(['step' => 'keywords']);
            $keywordData = $this->keywords->suggest($topic, $researchResults, $automation->keywords);
            $run->markStep('keywords');

            // 4. Article generation (validation + publish gate inside).
            $run->update(['step' => 'generation']);
            $result = $this->generator->generateForTopic($topic, $automation, [
                'research' => $researchResults,
                'research_run_id' => $run->research_run_id,
                'focus_keyword' => $keywordData['focus'],
                'secondary_keywords' => $keywordData['secondary'],
                'min_word_count' => $automation->min_word_count,
                'max_word_count' => $automation->max_word_count,
                'duplicate_threshold' => $automation->duplicate_threshold,
                'research_required' => (bool) $automation->research_enabled,
            ]);

            if (! ($result['success'] ?? false)) {
                return $this->failRun($run, 'Generation failed: '.($result['message'] ?? 'unknown error'));
            }

            $run->markStep('generation');

            $post = BlogPost::query()->findOrFail($result['post_id']);
            $generationId = AiGeneration::query()->where('blog_post_id', $post->id)->value('id');

            // 5. Featured image (never blocks publishing).
            $run->update(['step' => 'featured_image']);
            $this->images->forPost($post, (string) ($automation->featured_image_mode ?: ImageService::MODE_BRANDED));
            $run->markStep('featured_image');

            // 6. Finalize. Publication (if any) was decided by the
            // validation-gated auto-publish logic inside the generator.
            $run->update([
                'blog_post_id' => $post->id,
                'ai_generation_id' => $generationId,
                'published' => $post->status === 'published',
                'status' => PipelineRun::STATUS_COMPLETED,
                'step' => 'finalized',
                'finished_at' => now(),
                'error_message' => null,
            ]);

            AuditLog::record(
                'pipeline.completed',
                $post,
                ($run->published ? 'Auto-published' : 'Generated draft').' from pipeline slot '.$run->slot.': '.$post->title,
                ['slot' => $run->slot, 'topic_source' => $run->topic_source, 'attempts' => $run->attempts]
            );

            return $run;
        } catch (\Throwable $e) {
            Log::error('Pipeline run failed: '.$e->getMessage(), ['run_id' => $run->id, 'step' => $run->step]);

            return $this->failRun($run, 'Pipeline error at step "'.$run->step.'": '.Str::limit($e->getMessage(), 400));
        }
    }

    protected function failRun(PipelineRun $run, string $message): PipelineRun
    {
        $run->update([
            'status' => PipelineRun::STATUS_FAILED,
            'error_message' => $message,
            'finished_at' => now(),
        ]);

        AuditLog::record('pipeline.failed', null, $message, ['slot' => $run->slot, 'attempts' => $run->attempts]);

        return $run;
    }
}
