<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\BlogPost;
use App\Models\Tool;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * DatabaseSeeder guarantees:
 * - idempotent default data (running twice duplicates nothing),
 * - never overwrites an existing admin's password,
 * - never seeds provider API keys (encrypted fields stay empty).
 */
class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::query()->where('email', 'admin@stunningtouch.com')->count());
        $this->assertSame(12, Tool::query()->count());
        $this->assertSame(2, BlogPost::query()->count(), 'Sample posts must not be duplicated on re-seed.');
    }

    public function test_seeder_never_overwrites_an_existing_admin_password(): void
    {
        // An existing production-like admin with a custom password.
        User::query()->create([
            'name' => 'Real Admin',
            'email' => 'admin@stunningtouch.com',
            'password' => Hash::make('real-secret-8a'),
            'is_active' => true,
        ]);

        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@stunningtouch.com')->firstOrFail();
        $this->assertTrue(Hash::check('real-secret-8a', $admin->password), 'Existing admin password must never be overwritten.');
        $this->assertSame('Real Admin', $admin->name, 'Existing admin name must not be replaced.');
    }

    public function test_seeder_creates_default_admin_for_fresh_installations(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@stunningtouch.com')->firstOrFail();
        $this->assertTrue(Hash::check('ChangeMe123!', $admin->password), 'Fresh installs get the documented default password.');
        $this->assertTrue($admin->is_active);
    }

    public function test_seeder_never_seeds_provider_api_keys(): void
    {
        $this->seed(DatabaseSeeder::class);

        $setting = AiSetting::query()->firstOrFail();
        $this->assertNull($setting->api_key, 'AI provider credentials must be empty by default.');
        $this->assertFalse($setting->is_active, 'AI generation must be disabled by default.');
    }
}
