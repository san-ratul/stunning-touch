<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Admin password change (My Account → Change Password).
 *
 * Route: PUT admin/account/password — protected by auth + EnsureAdmin + CSRF.
 * Other-session invalidation works through the AuthenticateSession web
 * middleware (enabled in bootstrap/app.php) together with
 * Auth::logoutOtherDevices() in the controller.
 */
class AdminPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(string $password = 'old-password-9x'): User
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->firstOrFail();
        $user->forceFill(['password' => Hash::make($password)])->save();

        return $user->refresh();
    }

    protected function validPayload(array $overrides = []): array
    {
        return $overrides + [
            'current_password' => 'old-password-9x',
            'password' => 'new-password-7z',
            'password_confirmation' => 'new-password-7z',
        ];
    }

    // ---- Happy path ----

    public function test_authenticated_admin_can_change_password(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->from(route('admin.account.password'))
            ->put(route('admin.account.password.update'), $this->validPayload())
            ->assertRedirect(route('admin.account.password'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-7z', $user->password));
        $this->assertFalse(Hash::check('old-password-9x', $user->password));

        // Only the password may change: email/name/role stay untouched.
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email, 'name' => $user->name]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_password.changed']);
    }

    public function test_new_password_authenticates_and_old_one_does_not(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('admin.account.password.update'), $this->validPayload())
            ->assertRedirect();

        $this->assertTrue(Auth::validate(['email' => $user->email, 'password' => 'new-password-7z']));
        $this->assertFalse(Auth::validate(['email' => $user->email, 'password' => 'old-password-9x']));
    }

    public function test_password_is_never_stored_in_plaintext_or_returned(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('admin.account.password.update'), $this->validPayload())
            ->assertRedirect();

        $user->refresh();
        $this->assertNotSame('new-password-7z', $user->password);
        $this->assertStringStartsWith('$2y$', (string) $user->password, 'Password must be bcrypt-hashed.');

        $page = $this->actingAs($user)->get(route('admin.account.password'));
        $page->assertOk();
        $this->assertStringNotContainsString('new-password-7z', $page->getContent());
    }

    // ---- Validation failures ----

    public function test_incorrect_current_password_is_rejected(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('admin.account.password.update'), $this->validPayload([
                'current_password' => 'wrong-current-1a',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['current_password' => 'The current password is incorrect.']);

        $user->refresh();
        $this->assertTrue(Hash::check('old-password-9x', $user->password), 'Password must not change.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_password.change_failed']);
    }

    public function test_confirmation_mismatch_is_rejected(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('admin.account.password.update'), $this->validPayload([
                'password_confirmation' => 'different-confirm-2b',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['password']);

        $user->refresh();
        $this->assertTrue(Hash::check('old-password-9x', $user->password));
    }

    public function test_weak_password_is_rejected_according_to_rules(): void
    {
        $user = $this->admin();

        foreach (['short1a', 'onlyletterslongenough', '123456789012'] as $weak) {
            $this->actingAs($user)
                ->put(route('admin.account.password.update'), $this->validPayload([
                    'password' => $weak,
                    'password_confirmation' => $weak,
                ]))
                ->assertRedirect()
                ->assertSessionHasErrors(['password']);
        }

        $user->refresh();
        $this->assertTrue(Hash::check('old-password-9x', $user->password));
    }

    public function test_missing_current_password_is_rejected(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('admin.account.password.update'), $this->validPayload([
                'current_password' => '',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['current_password']);
    }

    // ---- Access control ----

    public function test_guest_cannot_access_the_password_change_page(): void
    {
        $this->get(route('admin.account.password'))
            ->assertRedirect(route('login'));

        $this->put(route('admin.account.password.update'), $this->validPayload())
            ->assertRedirect(route('login'));
    }

    public function test_deactivated_admin_cannot_access_password_management(): void
    {
        $user = $this->admin();
        $user->update(['is_active' => false]);

        $this->actingAs($user)
            ->get(route('admin.account.password'))
            ->assertRedirect(route('login'));
    }

    // ---- Session security ----

    public function test_current_session_stays_signed_in_after_password_change(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('admin.account.password.update'), $this->validPayload())
            ->assertRedirect();

        // The session used to make the change must still be authenticated.
        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_other_sessions_are_invalidated_after_password_change(): void
    {
        $user = $this->admin();
        $oldHash = $user->getAuthPassword();

        // The "other" browser session: an authenticated session holding the
        // old password hash, like AuthenticateSession would have stored it.
        $authKey = 'login_web_'.sha1('Illuminate\Auth\SessionGuard');

        $this->actingAs($user)
            ->put(route('admin.account.password.update'), $this->validPayload())
            ->assertRedirect();

        // A second session still holding the old hash must now be signed
        // out (redirected to login) by the AuthenticateSession middleware.
        $this->withSession([$authKey => $user->id, 'password_hash_web' => $oldHash])
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    // ---- Existing auth remains intact ----

    public function test_login_with_changed_password_works_end_to_end(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->put(route('admin.account.password.update'), $this->validPayload())
            ->assertRedirect();

        auth()->logout();
        session()->flush();

        $this->post(route('admin.login.attempt'), [
            'email' => $user->email,
            'password' => 'new-password-7z',
        ])->assertRedirect(route('admin.dashboard'));
    }
}
