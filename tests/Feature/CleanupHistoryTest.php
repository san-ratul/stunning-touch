<?php

namespace Tests\Feature;

use App\Models\AiGeneration;
use App\Models\BlogPost;
use App\Models\PipelineRun;
use App\Models\QualityReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function seedAgedHistory(): BlogPost
    {
        $published = BlogPost::create([
            'title' => 'Published Survivor',
            'slug' => 'published-survivor',
            'content' => '<h2>Body</h2><p>Text.</p>',
            'status' => 'published',
            'published_at' => now()->subDays(200),
            'source' => 'ai',
        ]);

        $draft = BlogPost::create([
            'title' => 'Old Draft',
            'slug' => 'old-draft',
            'content' => '<h2>Body</h2><p>Text.</p>',
            'status' => 'draft',
            'source' => 'ai',
        ]);

        $protectedGeneration = AiGeneration::create([
            'topic' => 'Protected generation',
            'status' => 'completed',
            'blog_post_id' => $published->id,
        ]);

        $prunableGeneration = AiGeneration::create([
            'topic' => 'Prunable generation',
            'status' => 'failed',
            'blog_post_id' => $draft->id,
        ]);

        $protectedReport = QualityReport::create([
            'blog_post_id' => $published->id,
            'ai_generation_id' => $protectedGeneration->id,
            'passed' => true,
            'word_count' => 900,
        ]);

        $prunableReport = QualityReport::create([
            'blog_post_id' => $draft->id,
            'passed' => false,
            'word_count' => 10,
        ]);

        $protectedRun = PipelineRun::create([
            'slot_date' => now()->subDays(200)->toDateString(),
            'slot' => '06:00',
            'status' => PipelineRun::STATUS_COMPLETED,
            'blog_post_id' => $published->id,
            'attempts' => 1,
        ]);

        $prunableRun = PipelineRun::create([
            'slot_date' => now()->subDays(200)->toDateString(),
            'slot' => '12:00',
            'status' => PipelineRun::STATUS_FAILED,
            'error_message' => 'old failure',
            'attempts' => 1,
        ]);

        // created_at is not mass-assignable; age the rows at the query level.
        $aged = now()->subDays(200);
        AiGeneration::query()->whereIn('id', [$protectedGeneration->id, $prunableGeneration->id])->update(['created_at' => $aged]);
        QualityReport::query()->whereIn('id', [$protectedReport->id, $prunableReport->id])->update(['created_at' => $aged]);
        PipelineRun::query()->whereIn('id', [$protectedRun->id, $prunableRun->id])->update(['created_at' => $aged]);

        return $published;
    }

    public function test_cleanup_prunes_old_history_but_never_published_provenance(): void
    {
        $published = $this->seedAgedHistory();

        $this->artisan('blog:cleanup-history', ['--days' => 90])->assertSuccessful();

        // The published post itself is never touched.
        $this->assertDatabaseHas('blog_posts', ['id' => $published->id, 'status' => 'published']);

        // Its provenance survives.
        $this->assertDatabaseHas('ai_generations', ['topic' => 'Protected generation']);
        $this->assertDatabaseHas('quality_reports', ['blog_post_id' => $published->id]);
        $this->assertDatabaseHas('blog_pipeline_runs', ['blog_post_id' => $published->id]);

        // Old unlinked / draft-linked history is pruned.
        $this->assertDatabaseMissing('ai_generations', ['topic' => 'Prunable generation']);
        $this->assertDatabaseMissing('blog_pipeline_runs', ['slot' => '12:00']);
        $this->assertEquals(1, QualityReport::query()->count());
        $this->assertEquals(1, PipelineRun::query()->count());
    }

    public function test_recent_history_is_kept(): void
    {
        AiGeneration::create([
            'topic' => 'Recent generation',
            'status' => 'completed',
        ]);

        $this->artisan('blog:cleanup-history', ['--days' => 90])->assertSuccessful();

        $this->assertDatabaseHas('ai_generations', ['topic' => 'Recent generation']);
    }

    public function test_retention_days_option_is_respected(): void
    {
        $generation = AiGeneration::create([
            'topic' => 'Ten day old generation',
            'status' => 'failed',
        ]);

        AiGeneration::query()->where('id', $generation->id)->update(['created_at' => now()->subDays(10)]);

        $this->artisan('blog:cleanup-history', ['--days' => 30])->assertSuccessful();
        $this->assertDatabaseHas('ai_generations', ['topic' => 'Ten day old generation']);

        $this->artisan('blog:cleanup-history', ['--days' => 7])->assertSuccessful();
        $this->assertDatabaseMissing('ai_generations', ['topic' => 'Ten day old generation']);
    }
}
