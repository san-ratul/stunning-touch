<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Models\Visitor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitorTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_views_are_recorded_with_privacy_hashing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get('/', [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36',
            'Referer' => 'https://www.google.com/search?q=tools',
        ])->assertStatus(200);

        $this->assertDatabaseCount('visitors', 1);
        $this->assertDatabaseCount('page_views', 1);

        $visitor = Visitor::first();
        // Visitor hash must not contain the raw IP
        $this->assertStringNotContainsString('127.0.0.1', $visitor->visitor_hash);
        $this->assertEquals(64, strlen($visitor->visitor_hash));

        $view = PageView::first();
        $this->assertEquals('https://www.google.com/search?q=tools', $view->referrer);
        $this->assertEquals('google.com', $view->referrer_domain);
        $this->assertEquals('desktop', $view->device_type);
        $this->assertEquals('home', $view->page_type);
    }

    public function test_bot_user_agents_are_not_tracked(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get('/', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->assertStatus(200);
        $this->get('/', ['User-Agent' => 'curl/8.0'])->assertStatus(200);

        $this->assertDatabaseCount('page_views', 0);
    }

    public function test_admin_and_api_routes_are_not_tracked(): void
    {
        $this->seed(DatabaseSeeder::class);
        $ua = ['User-Agent' => 'Mozilla/5.0 Chrome/120.0'];

        $this->get('/admin/login', $ua)->assertStatus(200);
        $this->post('/api/tool-usage', ['tool' => 'word-counter', 'success' => 'true'], $ua)->assertOk();

        $this->assertDatabaseCount('page_views', 0);
    }

    public function test_mobile_user_agent_classified_as_mobile(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get('/', ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile Safari/604.1']);

        $this->assertDatabaseHas('page_views', ['device_type' => 'mobile', 'browser' => 'Safari']);
    }

    public function test_returning_visitor_gets_one_visitor_record(): void
    {
        $this->seed(DatabaseSeeder::class);
        $ua = ['User-Agent' => 'Mozilla/5.0 Chrome/120.0'];

        $this->get('/', $ua);
        $this->get('/tools', $ua);
        $this->get('/word-counter', $ua);

        $this->assertDatabaseCount('visitors', 1);
        $this->assertDatabaseCount('page_views', 3);
    }

    public function test_aggregate_command_creates_daily_summary(): void
    {
        $this->seed(DatabaseSeeder::class);
        $ua = ['User-Agent' => 'Mozilla/5.0 Chrome/120.0'];

        $this->get('/', $ua);
        $this->post('/api/tool-usage', ['tool' => 'word-counter', 'success' => 'true'], $ua);

        $this->artisan('analytics:aggregate', ['--date' => now()->toDateString()])->assertSuccessful();

        $this->assertDatabaseHas('analytics_daily', [
            'date' => now()->toDateString(),
            'page_views' => 1,
            'visitors' => 1,
            'tool_usage' => 1,
            'tool_success' => 1,
        ]);
    }
}
