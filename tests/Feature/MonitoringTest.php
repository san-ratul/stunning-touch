<?php

namespace Tests\Feature;

use App\Models\AiGeneration;
use App\Models\AutomationSetting;
use App\Models\BlogPost;
use App\Models\BlogResearchSource;
use App\Models\PipelineRun;
use App\Models\QualityReport;
use App\Models\User;
use App\Support\BlogMetrics;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function seedHistory(): void
    {
        $post = BlogPost::create([
            'title' => 'Published AI Article',
            'slug' => 'published-ai-article',
            'content' => '<h2>Body</h2><p>Text.</p>',
            'status' => 'published',
            'published_at' => now(),
            'source' => 'ai',
        ]);

        BlogPost::create([
            'title' => 'Draft AI Article',
            'slug' => 'draft-ai-article',
            'content' => '<h2>Body</h2><p>Text.</p>',
            'status' => 'draft',
            'source' => 'ai',
        ]);

        AiGeneration::create([
            'topic' => 'Published topic',
            'status' => 'completed',
            'blog_post_id' => $post->id,
            'prompt_tokens' => 1000,
            'completion_tokens' => 1500,
        ]);

        AiGeneration::create([
            'topic' => 'Broken topic',
            'status' => 'failed',
            'error_message' => 'API down',
        ]);

        QualityReport::create([
            'blog_post_id' => $post->id,
            'passed' => false,
            'violations' => ['Too short.'],
            'word_count' => 50,
        ]);

        BlogResearchSource::create([
            'blog_post_id' => $post->id,
            'url' => 'https://en.wikipedia.org/wiki/Letter_case',
            'title' => 'Letter case',
            'source_type' => 'wikipedia',
            'fetched_at' => now(),
        ]);
        BlogResearchSource::create([
            'blog_post_id' => $post->id,
            'url' => 'https://example.com/second',
            'title' => 'Second source',
            'source_type' => 'web',
            'fetched_at' => now(),
        ]);

        PipelineRun::create([
            'slot_date' => today(),
            'slot' => '06:00',
            'status' => PipelineRun::STATUS_COMPLETED,
            'step' => 'finalized',
            'topic' => 'Published topic',
            'blog_post_id' => $post->id,
            'published' => true,
            'attempts' => 2,
            'started_at' => now()->subSeconds(120),
            'finished_at' => now(),
        ]);
    }

    public function test_metrics_are_computed_from_real_records(): void
    {
        $this->seedHistory();

        $metrics = BlogMetrics::all();

        $this->assertEquals(2, $metrics['total_generations']);
        $this->assertEquals(1, $metrics['successful_generations']);
        $this->assertEquals(1, $metrics['failed_generations']);
        $this->assertEquals(1000, $metrics['prompt_tokens_total']);
        $this->assertEquals(1500, $metrics['completion_tokens_total']);
        $this->assertEquals(1, $metrics['posts_awaiting_review']);
        $this->assertEquals(1, $metrics['validation_failures']);
        $this->assertEquals(2.0, $metrics['avg_research_sources_per_article']);
        $this->assertEquals(0, $metrics['pipeline_failures']);
        $this->assertEquals(120, $metrics['avg_generation_seconds']);
        $this->assertEquals(3, $metrics['posts_per_day_target']);
        $this->assertGreaterThanOrEqual(0, $metrics['auto_published_posts']);
    }

    public function test_today_metrics_are_computed(): void
    {
        $this->seedHistory();

        $today = BlogMetrics::today();

        $this->assertEquals(1, $today['published_today']);
        $this->assertEquals(1, $today['completed_runs_today']);
        $this->assertEquals(0, $today['failed_runs_today']);
        $this->assertEquals(1, $today['pending_posts']);
    }

    public function test_guests_cannot_access_monitoring(): void
    {
        $this->get(route('admin.monitoring.index'))->assertRedirect('/admin/login');
    }

    public function test_admin_monitoring_page_renders_with_metrics(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedHistory();

        // AI provider is unconfigured: health check must degrade gracefully.
        Http::fake(['*' => Http::response('', 500)]);

        $response = $this->actingAs(User::first())
            ->get(route('admin.monitoring.index'));

        $response->assertOk();
        $response->assertSee('Pipeline execution history');
        $response->assertSee('Published topic');
    }

    public function test_dashboard_shows_automation_widget(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seedHistory();

        AutomationSetting::current()->update(['posts_per_day' => 3]);

        $response = $this->actingAs(User::first())
            ->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('AI Blogging — Today');
        $response->assertSee('1 / 3');
    }
}
