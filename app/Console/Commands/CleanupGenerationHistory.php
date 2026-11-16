<?php

namespace App\Console\Commands;

use App\Models\AiGeneration;
use App\Models\AuditLog;
use App\Models\BlogPost;
use App\Models\PipelineRun;
use App\Models\QualityReport;
use App\Models\ResearchRun;
use App\Models\SiteSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupGenerationHistory extends Command
{
    protected $signature = 'blog:cleanup-history {--days= : Retention window in days (default from site setting, fallback 90)}';

    protected $description = 'Prune old generation/research/pipeline history while never touching published blog content';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: SiteSetting::query()->where('key', 'history_retention_days')->value('value') ?: 90);
        $days = max(7, $days);

        $cutoff = now()->subDays($days);

        // Published post IDs are never pruned: their provenance (generations,
        // reports, research, pipeline runs) is preserved for audit purposes.
        $publishedPostIds = BlogPost::query()->where('status', 'published')->pluck('id');
        $protectedGenerationIds = AiGeneration::query()
            ->whereIn('blog_post_id', $publishedPostIds)
            ->pluck('id');

        $pruned = [];

        $pruned['pipeline_runs'] = PipelineRun::query()
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNull('blog_post_id')->orWhereNotIn('blog_post_id', $publishedPostIds))
            ->delete();

        $pruned['quality_reports'] = QualityReport::query()
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q
                ->whereNull('ai_generation_id')
                ->orWhereNotIn('ai_generation_id', $protectedGenerationIds))
            ->delete();

        $pruned['research_runs'] = ResearchRun::query()
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNull('ai_generation_id')->orWhereNotIn('ai_generation_id', $protectedGenerationIds))
            ->delete();

        $pruned['ai_generations'] = AiGeneration::query()
            ->where('created_at', '<', $cutoff)
            ->whereNotIn('id', $protectedGenerationIds)
            ->delete();

        $total = array_sum($pruned);

        $this->info("Cleanup complete (retention {$days} days): ".json_encode($pruned)." — {$total} record(s) pruned. Published posts and their provenance were preserved.");

        AuditLog::record('history.cleaned', null, "Pruned {$total} history record(s) older than {$days} days", $pruned);
        Log::info('blog:cleanup-history completed', ['days' => $days] + $pruned);

        return self::SUCCESS;
    }
}
