<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_admin(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/tools')->assertRedirect('/admin/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/admin/login')->assertStatus(200)->assertSee('Sign In');
    }

    public function test_admin_can_login_with_valid_credentials(): void
    {
        $this->seed(DatabaseSeeder::class);

        $response = $this->post('/admin/login', [
            'email' => 'admin@stunningtouch.com',
            'password' => 'ChangeMe123!',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs(User::first());
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.login']);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->post('/admin/login', [
            'email' => 'admin@stunningtouch.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_inactive_admin_cannot_login(): void
    {
        $this->seed(DatabaseSeeder::class);
        User::query()->update(['is_active' => false]);

        $this->post('/admin/login', [
            'email' => 'admin@stunningtouch.com',
            'password' => 'ChangeMe123!',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_admin_can_logout(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::first();

        $this->actingAs($admin)->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $this->seed(DatabaseSeeder::class);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/admin/login', [
                'email' => 'admin@stunningtouch.com',
                'password' => 'wrong',
            ]);
        }

        $this->post('/admin/login', [
            'email' => 'admin@stunningtouch.com',
            'password' => 'ChangeMe123!',
        ])->assertStatus(429);
    }
}
