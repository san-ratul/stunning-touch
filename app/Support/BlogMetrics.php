<?php

namespace App\Support;

use App\Models\AiGeneration;
use App\Models\AuditLog;
use App\Models\AutomationSetting;
use App\Models\BlogPost;
use App\Models\BlogResearchSource;
use App\Models\ImageSetting;
use App\Models\PipelineRun;
use App\Models\QualityReport;
use App\Models\ResearchRun;
use App\Services\Ai\CloudflareImageProvider;

/**
 * Aggregated operational metrics for the autonomous blogging system.
 * All values are computed from real stored records — nothing estimated.
 */
class BlogMetrics
{
    public static function all(): array
    {
        $autoPublished = AuditLog::query()
            ->where('action', 'post.published')
            ->whereNull('user_id')
            ->count();

        $manualPublished = AuditLog::query()
            ->where('action', 'post.published')
            ->whereNotNull('user_id')
            ->count();

        $sourcesPerArticle = BlogResearchSource::query()
            ->whereNotNull('blog_post_id')
            ->get(['blog_post_id'])
            ->groupBy('blog_post_id')
            ->map(fn ($group) => $group->count());

        $durations = PipelineRun::query()
            ->where('status', PipelineRun::STATUS_COMPLETED)
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->get(['started_at', 'finished_at'])
            ->map(fn ($run) => $run->started_at->diffInSeconds($run->finished_at));

        $imageSettings = ImageSetting::current();
        $imageProvider = new CloudflareImageProvider;
        $imageUsage = $imageProvider->usageToday();

        return [
            'total_generations' => AiGeneration::query()->count(),
            'successful_generations' => AiGeneration::query()->where('status', 'completed')->count(),
            'failed_generations' => AiGeneration::query()->where('status', 'failed')->count(),
            'prompt_tokens_total' => (int) AiGeneration::query()->sum('prompt_tokens'),
            'completion_tokens_total' => (int) AiGeneration::query()->sum('completion_tokens'),
            'avg_generation_seconds' => $durations->isEmpty() ? null : (int) round($durations->avg()),
            'posts_awaiting_review' => BlogPost::query()->where('source', 'ai')->where('status', 'draft')->count(),
            'auto_published_posts' => $autoPublished,
            'manual_published_posts' => $manualPublished,
            'avg_research_sources_per_article' => $sourcesPerArticle->isEmpty() ? 0.0 : round($sourcesPerArticle->avg(), 1),
            'validation_failures' => QualityReport::query()->where('passed', false)->count(),
            'pipeline_failures' => PipelineRun::query()->where('status', PipelineRun::STATUS_FAILED)->count(),
            'retry_count' => (int) PipelineRun::query()->sum('attempts') - PipelineRun::query()->count(),
            'research_runs' => ResearchRun::query()->count(),
            'posts_per_day_target' => app(AutomationSetting::class)::current()->posts_per_day ?? 3,
            'image_provider' => (string) ($imageSettings->provider ?? 'none'),
            'image_model' => (string) ($imageSettings->model ?? ($imageSettings->isCloudflare() ? CloudflareImageProvider::DEFAULT_MODEL : '')),
            'image_daily_limit' => $imageUsage['limit'],
            'image_success_today' => $imageUsage['success'],
            'image_failed_today' => $imageUsage['failed'],
            'image_remaining_today' => $imageUsage['remaining'],
            'image_last_success_at' => $imageProvider->lastSuccessAt(),
            'image_recent_errors' => $imageProvider->recentFailures(),
        ];
    }

    /**
     * Today's publishing progress for the dashboard widget.
     */
    public static function today(): array
    {
        $today = now()->toDateString();

        $runsToday = PipelineRun::query()->whereDate('created_at', $today);

        return [
            'published_today' => PipelineRun::query()
                ->whereDate('created_at', $today)
                ->where('published', true)
                ->count(),
            'completed_runs_today' => (clone $runsToday)->where('status', PipelineRun::STATUS_COMPLETED)->count(),
            'failed_runs_today' => (clone $runsToday)->where('status', PipelineRun::STATUS_FAILED)->count(),
            'pending_posts' => BlogPost::query()->where('source', 'ai')->where('status', 'draft')->count(),
        ];
    }
}
