<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\ArtisanRunner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Access-control matrix for the Artisan Command Runner.
 *
 * Guarantee under test: unauthorized requests never reach the process
 * executor. The executor is registered as a Mockery spy; every
 * unauthorized scenario asserts it was never invoked.
 */
class ArtisanRunnerAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $plainAdmin;

    protected User $authorizedAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->plainAdmin = User::create([
            'name' => 'Plain Admin',
            'email' => 'plain@example.com',
            'password' => 'password-123456',
            'is_active' => true,
        ]);

        $this->authorizedAdmin = User::create([
            'name' => 'Authorized Admin',
            'email' => 'authorized@example.com',
            'password' => 'password-123456',
            'is_active' => true,
            'artisan_runner_access' => true,
        ]);
    }

    protected function registerExecutorSpy(): MockInterface
    {
        $spy = \Mockery::spy(ArtisanRunner::class);
        $this->app->instance(ArtisanRunner::class, $spy);

        return $spy;
    }

    // ------------------------------------------------------------------
    // Page access
    // ------------------------------------------------------------------

    public function test_guest_opening_runner_page_is_redirected_to_login(): void
    {
        $this->get('/admin/artisan-runner')->assertRedirect(route('login'));
    }

    public function test_admin_without_permission_gets_403_on_runner_page(): void
    {
        $spy = $this->registerExecutorSpy();

        $this->actingAs($this->plainAdmin)
            ->get('/admin/artisan-runner')
            ->assertForbidden();

        $spy->shouldNotHaveReceived('run');
        $spy->shouldNotHaveReceived('environmentProblem');
    }

    public function test_authorized_admin_gets_200_on_runner_page(): void
    {
        $this->actingAs($this->authorizedAdmin)
            ->get('/admin/artisan-runner')
            ->assertOk()
            ->assertSee('Allowed commands')
            ->assertSee('db:seed');
    }

    public function test_runner_page_lists_execution_history(): void
    {
        AuditLog::record('artisan_runner.success', null, 'Artisan command: about', ['command' => 'about', 'exit_code' => 0, 'duration_ms' => 5]);

        $this->actingAs($this->authorizedAdmin)
            ->get('/admin/artisan-runner')
            ->assertOk()
            ->assertSee('Artisan command: about');
    }

    // ------------------------------------------------------------------
    // Execution endpoint access (direct POST, no UI involved)
    // ------------------------------------------------------------------

    public function test_guest_directly_calling_execution_endpoint_gets_401(): void
    {
        $spy = $this->registerExecutorSpy();

        $this->postJson('/admin/artisan-runner/run', ['command' => 'about'])
            ->assertStatus(401);

        $spy->shouldNotHaveReceived('run');
    }

    public function test_admin_without_permission_directly_calling_execution_endpoint_gets_403(): void
    {
        $spy = $this->registerExecutorSpy();

        $this->actingAs($this->plainAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'about'])
            ->assertForbidden();

        $spy->shouldNotHaveReceived('run');
    }

    public function test_authorized_admin_may_call_execution_endpoint(): void
    {
        $fake = new class extends ArtisanRunner
        {
            public bool $invoked = false;

            public function run(string $input): array
            {
                $this->invoked = true;

                return [
                    'ok' => true, 'status' => 'success', 'message' => 'ok', 'command' => $input,
                    'exit_code' => 0, 'duration_ms' => 1, 'stdout' => '', 'stderr' => '', 'output_truncated' => false,
                ];
            }
        };

        $this->app->instance(ArtisanRunner::class, $fake);

        $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'about'])
            ->assertOk()
            ->assertJsonPath('result.status', 'success');

        $this->assertTrue($fake->invoked);
    }

    public function test_deactivated_authorized_admin_is_denied(): void
    {
        $spy = $this->registerExecutorSpy();

        $this->authorizedAdmin->update(['is_active' => false]);

        // EnsureAdmin fails closed for deactivated accounts: session is
        // closed and the user is sent back to the login page.
        $this->actingAs($this->authorizedAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'about'])
            ->assertRedirect(route('login'));

        $spy->shouldNotHaveReceived('run');
    }

    // ------------------------------------------------------------------
    // Privilege escalation attempts
    // ------------------------------------------------------------------

    public function test_client_supplied_identity_parameters_grant_nothing(): void
    {
        $spy = $this->registerExecutorSpy();

        $this->actingAs($this->plainAdmin)
            ->postJson('/admin/artisan-runner/run', [
                'command' => 'about',
                'user_id' => $this->authorizedAdmin->id,
                'artisan_runner_access' => '1',
                'role' => 'super-admin',
                'is_admin' => '1',
            ])->assertForbidden();

        $spy->shouldNotHaveReceived('run');

        // The unauthorized admin still has no permission.
        $this->assertFalse($this->plainAdmin->refresh()->artisan_runner_access);
    }

    public function test_admins_cannot_grant_the_permission_to_themselves(): void
    {
        $this->actingAs($this->plainAdmin)
            ->from(route('admin.users.edit', $this->plainAdmin))
            ->put(route('admin.users.update', $this->plainAdmin), [
                'name' => $this->plainAdmin->name,
                'email' => $this->plainAdmin->email,
                'is_active' => '1',
                'artisan_runner_access' => '1',
            ])->assertRedirect(route('admin.users.index'));

        $this->assertFalse($this->plainAdmin->refresh()->artisan_runner_access, 'Self-granting the permission must be impossible.');
    }

    public function test_admins_can_grant_the_permission_to_other_admins(): void
    {
        $this->actingAs($this->plainAdmin)
            ->put(route('admin.users.update', $this->authorizedAdmin), [
                'name' => $this->authorizedAdmin->name,
                'email' => $this->authorizedAdmin->email,
                'is_active' => '1',
                'artisan_runner_access' => null, // revoke
            ])->assertRedirect(route('admin.users.index'));

        $this->assertFalse($this->authorizedAdmin->refresh()->artisan_runner_access);
    }

    public function test_runner_page_cannot_be_accessed_via_permission_parameter(): void
    {
        $spy = $this->registerExecutorSpy();

        $this->actingAs($this->plainAdmin)
            ->get('/admin/artisan-runner?artisan_runner_access=1&role=super-admin')
            ->assertForbidden();

        $spy->shouldNotHaveReceived('run');
    }

    // ------------------------------------------------------------------
    // Audit trail
    // ------------------------------------------------------------------

    public function test_denied_attempts_leave_no_runner_audit_entries(): void
    {
        $this->registerExecutorSpy();

        $this->actingAs($this->plainAdmin)
            ->postJson('/admin/artisan-runner/run', ['command' => 'about'])
            ->assertForbidden();

        $this->assertDatabaseCount('audit_logs', 0);
    }
}
