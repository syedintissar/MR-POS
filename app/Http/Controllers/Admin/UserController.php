<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Support\PermissionCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly SettingsService $settings) {}

    public function index(Request $request): View
    {
        $this->authorize('users.view');

        $users = User::query()
            ->with('roles')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('username', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->orderByDesc('is_active')->orderBy('name')
            ->paginate(25)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function create(): View
    {
        $this->authorize('users.manage');

        return view('admin.users.form', ['user' => new User(['is_active' => true, 'must_change_password' => true]), 'roles' => $this->assignableRoles()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('users.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'regex:/^[A-Za-z0-9._-]+$/', 'max:50', Rule::unique('users', 'username')],
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in($this->assignableRoles()->pluck('name')->all())],
            'password' => ['required', Password::min($this->minLength())->max(100)],
        ], ['username.regex' => 'The username may contain only letters, numbers, dot, dash and underscore (no spaces).']);

        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name' => $data['name'],
                'username' => strtolower($data['username']),
                'email' => ($data['email'] ?? null) ?: null,
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'is_active' => $request->boolean('is_active', true),
                // A password an administrator typed is known to them, so the user must replace it at first login.
                'must_change_password' => true,
            ]);
            $user->assignRole($data['role']);

            $this->audit->log('user_created', 'users', "User {$user->username} created with role {$data['role']}", $user, new: [
                'username' => $user->username, 'name' => $user->name, 'role' => $data['role'], 'is_active' => $user->is_active,
            ]);

            return $user;
        });

        return redirect()->route('users.index')->with('success', "User {$user->username} created. They must choose a new password at first login.");
    }

    public function edit(User $user): View
    {
        $this->authorize('users.manage');

        return view('admin.users.form', ['user' => $user, 'roles' => $this->assignableRoles($user)]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('users.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in($this->assignableRoles($user)->pluck('name')->all())],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $active = $request->boolean('is_active');
        $oldRole = $user->roleName();

        DB::transaction(function () use ($user, $data, $active, $oldRole, $request) {
            // Serialise changes to administrators: two admins demoting each other at the same moment must not both succeed.
            User::query()->activeAdmins()->lockForUpdate()->get(['users.id']);
            $this->guardAdminChange($user->refresh(), $request, $data['role'], $active);

            $old = ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'role' => $oldRole, 'is_active' => $user->is_active];

            $user->fill(['name' => $data['name'], 'email' => ($data['email'] ?? null) ?: null, 'phone' => $data['phone'] ?? null, 'is_active' => $active])->save();
            if ($oldRole !== $data['role']) {
                $user->syncRoles([$data['role']]);
            }

            $new = ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'role' => $data['role'], 'is_active' => $active];
            $event = $oldRole !== $data['role'] ? 'user_role_changed' : ($old['is_active'] !== $active ? ($active ? 'user_activated' : 'user_deactivated') : 'user_updated');
            $this->audit->log($event, 'users', "User {$user->username} updated", $user, $old, $new);

            if (! $active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->authorize('users.manage');

        $data = $request->validate(['password' => ['required', Password::min($this->minLength())->max(100)]]);

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['password' => $data['password'], 'must_change_password' => true])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->audit->log('user_password_reset', 'users', "Password reset for {$user->username} by an administrator", $user);
        });

        return back()->with('success', "Password reset for {$user->username}. They must choose a new one at next login.");
    }

    /** Roles the current user may hand out: only an Admin can create another Admin. */
    private function assignableRoles(?User $editing = null)
    {
        $roles = Role::query()->orderBy('name')->get();

        if (! auth()->user()->isAdmin()) {
            $roles = $roles->reject(fn ($r) => $r->name === PermissionCatalogue::ADMIN);
            if ($editing?->isAdmin()) {
                $roles = $roles->push(Role::findByName(PermissionCatalogue::ADMIN));
            }
        }

        return $roles->values();
    }

    /** Protect the account of the person acting and the last active Admin. */
    private function guardAdminChange(User $target, Request $request, string $newRole, bool $newActive): void
    {
        // 1. Authorisation first: only an administrator may touch an administrator account.
        if (! $request->user()->isAdmin() && ($target->isAdmin() || $newRole === PermissionCatalogue::ADMIN)) {
            abort(403, 'Only an administrator can change an administrator account.');
        }

        // 2. Protect the person acting.
        $self = $request->user()->is($target);
        if ($self && ! $newActive) {
            abort(422, 'You cannot deactivate your own account.');
        }
        if ($self && $target->isAdmin() && $newRole !== PermissionCatalogue::ADMIN) {
            abort(422, 'You cannot remove the Admin role from yourself.');
        }

        // 3. There must always be at least one active administrator.
        if ($target->isAdmin() && $target->is_active && ($newRole !== PermissionCatalogue::ADMIN || ! $newActive)) {
            if (User::query()->activeAdmins()->whereKeyNot($target->id)->doesntExist()) {
                abort(422, 'This is the last active administrator. Create or activate another administrator first.');
            }
        }
    }

    private function minLength(): int
    {
        return max(6, $this->settings->int('security.password_min_length'));
    }
}
