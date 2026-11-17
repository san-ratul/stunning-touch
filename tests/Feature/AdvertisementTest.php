<?php

namespace Tests\Feature;

use App\Models\Advertisement;
use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdvertisementTest extends TestCase
{
    use RefreshDatabase;

    protected const MONETAG_SNIPPET = <<<'HTML'
    <script>
    (function(s){
        s.dataset.zone='12284455',
        s.src='https://n6wxm.com/vignette.min.js'
    })([document.documentElement, document.body]
        .filter(Boolean)
        .pop()
        .appendChild(document.createElement('script')))
    </script>
    HTML;

    protected function admin(): User
    {
        $this->seed(DatabaseSeeder::class);

        return User::first();
    }

    protected function enableWithScript(string $script): void
    {
        $this->actingAs($this->admin())->put('/admin/advertisements', [
            'advertisement_enabled' => '1',
            'advertisement_script' => $script,
        ])->assertRedirect(route('admin.advertisements.edit'));
    }

    public function test_admin_can_save_and_edit_advertisement_code(): void
    {
        $this->enableWithScript(self::MONETAG_SNIPPET);

        $this->assertSame('1', SiteSetting::get('advertisement_enabled'));
        $this->assertSame(self::MONETAG_SNIPPET, SiteSetting::get('advertisement_script'));

        $edit = $this->actingAs($this->admin())->get('/admin/advertisements');
        $edit->assertOk();
        // The saved script is shown escaped inside the textarea, never executed.
        $edit->assertSee(self::MONETAG_SNIPPET);
    }

    public function test_guests_cannot_modify_advertisement_settings(): void
    {
        SiteSetting::set('advertisement_enabled', '1');
        SiteSetting::set('advertisement_script', self::MONETAG_SNIPPET);

        $this->put('/admin/advertisements', [
            'advertisement_enabled' => '0',
            'advertisement_script' => 'evil',
        ])->assertRedirect('/admin/login');

        $this->assertSame('1', SiteSetting::get('advertisement_enabled'));
        $this->assertSame(self::MONETAG_SNIPPET, SiteSetting::get('advertisement_script'));
    }

    public function test_enabled_advertisement_renders_in_public_footer(): void
    {
        $this->enableWithScript(self::MONETAG_SNIPPET);

        $home = $this->get('/');
        $home->assertOk();
        $home->assertSee(self::MONETAG_SNIPPET, false);
    }

    public function test_disabled_advertisement_never_renders(): void
    {
        SiteSetting::set('advertisement_enabled', '0');
        SiteSetting::set('advertisement_script', self::MONETAG_SNIPPET);

        $this->get('/')->assertOk()->assertDontSee('n6wxm.com', false);
        $this->get('/tools')->assertOk()->assertDontSee('n6wxm.com', false);
    }

    public function test_script_is_injected_exactly_once_before_body_close(): void
    {
        $this->enableWithScript(self::MONETAG_SNIPPET);

        $html = (string) $this->get('/')->getContent();

        $this->assertSame(1, substr_count($html, 'vignette.min.js'));
        $this->assertSame(1, substr_count($html, self::MONETAG_SNIPPET));

        $injection = strpos($html, self::MONETAG_SNIPPET);
        $bodyClose = strrpos($html, '</body>');
        $this->assertNotFalse($injection);
        $this->assertNotFalse($bodyClose);
        $this->assertGreaterThan($injection, $bodyClose);
        $between = substr($html, $injection + strlen(self::MONETAG_SNIPPET), $bodyClose - ($injection + strlen(self::MONETAG_SNIPPET)));
        $this->assertSame('', trim($between));
    }

    public function test_exact_script_content_is_preserved(): void
    {
        $this->enableWithScript(self::MONETAG_SNIPPET);

        $html = (string) $this->get('/')->getContent();

        $this->assertStringContainsString("s.dataset.zone='12284455',", $html);
        $this->assertStringContainsString("s.src='https://n6wxm.com/vignette.min.js'", $html);
        $this->assertStringNotContainsString('&lt;script', $html);
        $this->assertStringNotContainsString('&amp;quot;', $html);
    }

    public function test_multiple_script_tags_are_supported(): void
    {
        $script = self::MONETAG_SNIPPET."\n<script>console.log('second ad script');</script>";

        $this->enableWithScript($script);

        $html = (string) $this->get('/')->getContent();

        $this->assertSame(1, substr_count($html, 'vignette.min.js'));
        $this->assertSame(1, substr_count($html, "console.log('second ad script');"));
    }

    public function test_admin_pages_never_render_or_execute_advertisement_script(): void
    {
        $this->enableWithScript(self::MONETAG_SNIPPET);

        $this->get('/admin')->assertOk()->assertDontSee('vignette.min.js', false);
        $this->get('/admin/login')->assertRedirect()->assertDontSee('vignette.min.js', false);
        $this->get('/admin/tools')->assertOk()->assertDontSee('vignette.min.js', false);

        // The admin edit page shows the script only escaped inside a textarea;
        // the raw snippet must never appear unescaped (executable) there.
        $adminEdit = $this->get('/admin/advertisements');
        $adminEdit->assertOk();
        $this->assertStringNotContainsString(self::MONETAG_SNIPPET, (string) $adminEdit->getContent());
    }

    public function test_error_json_sitemap_and_robot_responses_are_excluded(): void
    {
        $this->enableWithScript(self::MONETAG_SNIPPET);

        $this->get('/this-page-does-not-exist')->assertStatus(404)->assertDontSee('vignette.min.js', false);
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('vignette.min.js', false);
        $this->get('/robots.txt')->assertOk()->assertDontSee('vignette.min.js', false);

        $search = $this->get('/tools/search?q=a');
        $search->assertOk();
        $this->assertStringNotContainsString('vignette.min.js', (string) $search->getContent());
    }

    public function test_settings_change_takes_effect_immediately_without_deployment(): void
    {
        SiteSetting::set('advertisement_enabled', '0');
        SiteSetting::set('advertisement_script', self::MONETAG_SNIPPET);

        // Warm the forever cache with disabled state.
        $this->get('/')->assertOk()->assertDontSee('vignette.min.js', false);

        $this->enableWithScript(self::MONETAG_SNIPPET);

        // Cache was invalidated on save: the next render shows the script.
        $this->get('/')->assertOk()->assertSee('vignette.min.js', false);

        $this->actingAs($this->admin())->put('/admin/advertisements', [
            'advertisement_enabled' => '0',
            'advertisement_script' => self::MONETAG_SNIPPET,
        ])->assertRedirect();

        $this->get('/')->assertOk()->assertDontSee('vignette.min.js', false);
    }

    public function test_cache_is_invalidated_after_saving(): void
    {
        SiteSetting::set('advertisement_enabled', '0');
        SiteSetting::set('advertisement_script', '');

        // Warm the forever cache with the disabled state.
        $this->assertSame('0', SiteSetting::get('advertisement_enabled'));

        $this->enableWithScript(self::MONETAG_SNIPPET);

        // The stale cache entry was invalidated on save: the next read rebuilds it.
        $this->assertSame('1', SiteSetting::get('advertisement_enabled'));
        $this->assertSame(self::MONETAG_SNIPPET, SiteSetting::get('advertisement_script'));

        $cached = Cache::get('site_settings');
        $this->assertSame('1', $cached['advertisement_enabled']);
        $this->assertSame(self::MONETAG_SNIPPET, $cached['advertisement_script']);
    }

    public function test_existing_advertisement_records_are_preserved(): void
    {
        $this->seed(DatabaseSeeder::class);
        $placementId = DB::table('ad_placements')->first()->id;

        $legacy = Advertisement::create([
            'ad_placement_id' => $placementId,
            'name' => 'Legacy Banner',
            'provider' => 'monetag',
            'ad_type' => 'code',
            'ad_code' => '<script>legacy-ad();</script>',
            'device_targeting' => 'all',
            'is_active' => true,
        ]);

        $this->enableWithScript(self::MONETAG_SNIPPET);

        $legacy->refresh();
        $this->assertSame('<script>legacy-ad();</script>', $legacy->ad_code);
        $this->assertTrue($legacy->is_active);

        $count = DB::table('ad_placements')->count();
        $this->assertSame(11, $count);

        // Public pages render only the global footer script, never the legacy placement code.
        $this->get('/')->assertOk()->assertDontSee('legacy-ad();', false);
    }

    public function test_validation_rejects_invalid_status_and_oversized_script(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/advertisements', ['advertisement_enabled' => 'maybe'])
            ->assertSessionHasErrors('advertisement_enabled');

        $this->actingAs($this->admin())
            ->put('/admin/advertisements', [
                'advertisement_enabled' => '1',
                'advertisement_script' => str_repeat('a', 100001),
            ])
            ->assertSessionHasErrors('advertisement_script');
    }

    public function test_audit_log_records_advertisement_updates(): void
    {
        $this->enableWithScript(self::MONETAG_SNIPPET);

        $this->assertDatabaseHas('audit_logs', ['action' => 'advertisement.updated']);
    }
}
