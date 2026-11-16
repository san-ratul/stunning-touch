<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AccountController extends Controller
{
    /**
     * Change-password page for the signed-in administrator's own account.
     */
    public function edit(): View
    {
        return view('admin.account.password');
    }

    /**
     * Update the signed-in administrator's own password.
     *
     * Security model:
     * - Route is protected by the existing auth + EnsureAdmin + CSRF stack.
     * - The current password must be proven with a hash check before any
     *   change is applied.
     * - Only the password column is ever touched — email, name, role and
     *   activation state remain untouched.
     * - Passwords are never logged or returned in responses.
     * - Other sessions are invalidated after the change; the current
     *   session stays alive.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            // Same policy as admin user management: min 10 chars with at
            // least one letter and one digit; confirmation must match.
            'password' => ['required', 'string', 'min:10', 'regex:/[a-zA-Z]/', 'regex:/[0-9]/', 'confirmed'],
        ]);

        if (! Hash::check((string) $validated['current_password'], $user->password)) {
            AuditLog::record('admin_password.change_failed', $user, 'Password change rejected: current password did not match');

            return back()->withErrors([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        // The User model hashes on set ("hashed" cast); Hash facade used
        // here for the explicit verification above.
        $user->update(['password' => $validated['password']]);

        // Laravel's session security practice: other sessions (other
        // browsers/devices) are invalidated; this session stays signed in.
        Auth::logoutOtherDevices($validated['password']);

        AuditLog::record('admin_password.changed', $user, 'Admin changed their own password');

        return redirect()
            ->route('admin.account.password')
            ->with('success', 'Password changed successfully. Other sessions were signed out.');
    }
}
