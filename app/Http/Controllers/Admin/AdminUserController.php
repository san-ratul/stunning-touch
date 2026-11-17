<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(): View
    {
        return view('admin.users.index', [
            'users' => User::query()->withCount('auditLogs')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', ['user' => new User]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $user = User::create($data);
        AuditLog::record('admin_user.created', $user, 'Created admin user: '.$user->email);

        return redirect()->route('admin.users.index')->with('success', 'Admin user created.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user, withPassword: filled($request->input('password')));

        if (! filled($request->input('password'))) {
            unset($data['password']);
        }

        $user->update($data);
        AuditLog::record('admin_user.updated', $user, 'Updated admin user: '.$user->email);

        return redirect()->route('admin.users.index')->with('success', 'Admin user updated.');
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_if($user->id === auth()->id(), 422, 'You cannot delete your own account.');
        abort_if(User::query()->where('is_active', true)->count() <= 1, 422, 'At least one active admin is required.');

        AuditLog::record('admin_user.deleted', $user, 'Deleted admin user: '.$user->email);
        $user->delete();

        return redirect()->route('admin.users.index')->with('success', 'Admin user deleted.');
    }

    protected function validated(Request $request, ?User $user = null, bool $withPassword = true): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'.($user ? ','.$user->id : '')],
            'is_active' => ['nullable', 'boolean'],
        ];

        // Dedicated permission, default-deny. Administrators can grant it
        // to OTHER admins only — never to themselves, never via a request
        // parameter masquerading as their own identity.
        if (! $user || $user->id !== auth()->id()) {
            $rules['artisan_runner_access'] = ['nullable', 'boolean'];
        }

        if ($withPassword) {
            $rules['password'] = ['required', 'string', 'min:10', 'regex:/[a-zA-Z]/', 'regex:/[0-9]/'];
        }

        $validated = $request->validate($rules);
        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);

        if (isset($rules['artisan_runner_access'])) {
            $validated['artisan_runner_access'] = (bool) ($validated['artisan_runner_access'] ?? false);
        }

        return $validated;
    }
}
