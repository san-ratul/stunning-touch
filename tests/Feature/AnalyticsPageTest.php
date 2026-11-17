<?php

namespace Tests\Feature;

use App\Models\PageView;
use App\Models\User;
use App\Models\Visitor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_page_renders_with_referrer_traffic(): void
    {
        $this->seed(DatabaseSeeder::class);

        $visitor = Visitor::create([
            'visitor_hash' => str_repeat('a', 64),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        PageView::create([
            'visitor_id' => $visitor->id,
            'page_url' => '/',
            'page_type' => 'home',
            'referrer' => 'https://www.google.com/search?q=test',
            'referrer_domain' => 'google.com',
            'device_type' => 'desktop',
            'browser' => 'Chrome',
            'visited_at' => now(),
        ]);

        PageView::create([
            'visitor_id' => $visitor->id,
            'page_url' => '/tools',
            'page_type' => 'tools_index',
            'referrer' => 'https://facebook.com',
            'referrer_domain' => 'facebook.com',
            'device_type' => 'mobile',
            'browser' => 'Safari',
            'visited_at' => now(),
        ]);

        PageView::create([
            'visitor_id' => $visitor->id,
            'page_url' => '/about',
            'page_type' => 'static_page',
            'referrer' => null,
            'referrer_domain' => null,
            'device_type' => 'desktop',
            'browser' => 'Firefox',
            'visited_at' => now(),
        ]);

        $response = $this->actingAs(User::first())->get('/admin/analytics');

        $response->assertOk();
        $response->assertSee('google.com');
        $response->assertSee('facebook.com');
    }
}
