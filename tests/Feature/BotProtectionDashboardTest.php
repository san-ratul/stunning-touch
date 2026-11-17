<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Support\BotMetrics;
use App\Support\TrafficVerdict;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin dashboard access control and rendering. Dashboard values are only
 * ever real recorded events — no dummy data is rendered.
 */
class BotProtectionDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    protected function admin(): User
    {
        return User::query()->where('email', 'admin@stunningtouch.com')->firstOrFail();
    }

    public function test_guests_cannot_access_the_dashboard(): void
    {
        $this->get('/admin/bot-protection')->assertRedirect('/admin/login');
    }

    public function test_inactive_users_cannot_access_the_dashboard(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)
            ->get('/admin/bot-protection')
            ->assertRedirect('/admin/login');
    }

    public function test_inactive_users_cannot_change_settings(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)
            ->put('/admin/bot-protection/settings', [
                'bot_protection_enabled' => '0',
                'bot_block_automated_posts' => '0',
                'bot_page_rate_limit' => '60',
                'bot_suspicious_rate_limit' => '30',
                'confirm_disable' => '1',
            ])
            ->assertRedirect('/admin/login');

        $this->assertSame('1', SiteSetting::get('bot_protection_enabled', '1'));
    }

    public function test_admin_can_view_the_dashboard(): void
    {
        BotMetrics::recordNamed(TrafficVerdict::SUSPICIOUS, 'googlebot', 'login', BotMetrics::ACTION_BLOCKED, 'policy-post-endpoint');

        $this->actingAs($this->admin())
            ->get('/admin/bot-protection')
            ->assertOk()
            ->assertSee('Total Bot Requests')
            ->assertSee('Blocked Requests')
            ->assertSee('Allowed Bot Requests')
            ->assertSee('Rate-Limited Requests')
            ->assertSee('not measurable from this application', false);
    }

    public function test_dashboard_shows_only_real_recorded_values(): void
    {
        // No events recorded: every KPI renders zero — nothing invented.
        $this->actingAs($this->admin())
            ->get('/admin/bot-protection')
            ->assertOk()
            ->assertSee('No automated traffic recorded yet.')
            ->assertSee('No security events recorded yet.');
    }

    public function test_dashboard_date_filtering_works(): void
    {
        // Older bucket (outside the 24h window but inside 7 days).
        DB::table('bot_traffic_metrics')->insert([
            'date' => now()->subDays(3)->toDateString(),
            'hour' => 5,
            'category' => TrafficVerdict::KNOWN_BOT,
            'bot_name' => 'curl',
            'endpoint_group' => 'page',
            'action' => BotMetrics::ACTION_ALLOWED,
            'requests' => 7,
        ]);

        $this->actingAs($this->admin())->get('/admin/bot-protection?range=24h')
            ->assertOk()
            ->assertSee('No automated traffic recorded yet.');

        $this->actingAs($this->admin())->get('/admin/bot-protection?range=7d')
            ->assertOk()
            ->assertDontSee('No automated traffic recorded yet.');
    }

    public function test_admin_can_update_settings_with_limits(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/bot-protection/settings', [
                'bot_protection_enabled' => '1',
                'bot_block_automated_posts' => '1',
                'bot_page_rate_limit' => '120',
                'bot_suspicious_rate_limit' => '40',
            ])
            ->assertRedirect();

        $this->assertSame('120', SiteSetting::get('bot_page_rate_limit'));

        // Out-of-bounds thresholds are rejected.
        $this->actingAs($this->admin())
            ->put('/admin/bot-protection/settings', [
                'bot_protection_enabled' => '1',
                'bot_block_automated_posts' => '1',
                'bot_page_rate_limit' => '100000',
                'bot_suspicious_rate_limit' => '30',
            ])
            ->assertSessionHasErrors('bot_page_rate_limit');
    }

    public function test_disabling_protection_requires_explicit_confirmation(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/bot-protection/settings', [
                'bot_protection_enabled' => '0',
                'bot_block_automated_posts' => '1',
                'bot_page_rate_limit' => '60',
                'bot_suspicious_rate_limit' => '30',
            ])
            ->assertSessionHasErrors('confirm_disable');

        $this->assertSame('1', SiteSetting::get('bot_protection_enabled', '1'));

        $this->actingAs($this->admin())
            ->put('/admin/bot-protection/settings', [
                'bot_protection_enabled' => '0',
                'bot_block_automated_posts' => '1',
                'bot_page_rate_limit' => '60',
                'bot_suspicious_rate_limit' => '30',
                'confirm_disable' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('0', SiteSetting::get('bot_protection_enabled', '1'));
    }
}
