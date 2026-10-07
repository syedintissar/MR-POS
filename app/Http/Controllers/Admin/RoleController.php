<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\PermissionCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('roles.manage');

        $roles = Role::query()->withCount(['users', 'permissions'])->orderBy('name')->get();

        return view('admin.roles.index', ['roles' => $roles, 'defaultNames' => array_keys(PermissionCatalogue::defaultRoles())]);
    }

    public function create(): View
    {
        $this->authorize('roles.manage');

        return view('admin.roles.form', ['role' => new Role(['name' => '']), 'modules' => PermissionCatalogue::modules(), 'granted' => [], 'locked' => false]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('roles.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalogue::all())],
        ]);

        DB::transaction(function () use ($data) {
            $role = Role::create(['name' => trim($data['name']), 'guard_name' => 'web']);
            $role->syncPermissions($data['permissions'] ?? []);
            $this->audit->log('role_created', 'roles', "Role {$role->name} created", $role, new: ['permissions' => $data['permissions'] ?? []]);
        });

        return redirect()->route('roles.index')->with('success', 'Role created.');
    }

    public function edit(Role $role): View
    {
        $this->authorize('roles.manage');

        return view('admin.roles.form', [
            'role' => $role,
            'modules' => PermissionCatalogue::modules(),
            'granted' => $role->permissions->pluck('name')->all(),
            'locked' => $role->name === PermissionCatalogue::ADMIN,
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('roles.manage');

        if ($role->name === PermissionCatalogue::ADMIN) {
            abort(422, 'The Admin role always has every permission and cannot be changed.');
        }

        $isDefault = in_array($role->name, array_keys(PermissionCatalogue::defaultRoles()), true);

        $data = $request->validate([
            'name' => [$isDefault ? 'prohibited' : 'required', 'string', 'max:50', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalogue::all())],
        ]);

        DB::transaction(function () use ($role, $data) {
            $before = $role->permissions->pluck('name')->sort()->values()->all();
            $oldName = $role->name;

            if (isset($data['name']) && ! in_array($role->name, array_keys(PermissionCatalogue::defaultRoles()), true)) {
                $role->name = trim($data['name']);
                $role->save();
            }

            $role->syncPermissions($data['permissions'] ?? []);
            $after = $role->fresh()->permissions->pluck('name')->sort()->values()->all();

            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));

            $this->audit->log(
                'role_permissions_changed',
                'roles',
                "Role {$oldName} changed (+".count($added).' / -'.count($removed).' permissions)',
                $role,
                ['removed' => $removed, 'name' => $oldName],
                ['added' => $added, 'name' => $role->name],
            );
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('roles.index')->with('success', 'Role saved. People with this role get the change the next time they open a page.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('roles.manage');

        if (in_array($role->name, array_keys(PermissionCatalogue::defaultRoles()), true)) {
            abort(422, 'The built-in roles cannot be deleted.');
        }
        if ($role->users()->exists()) {
            return back()->withErrors(['role' => 'This role is still assigned to users. Move them to another role first.']);
        }

        DB::transaction(function () use ($role) {
            $this->audit->log('role_deleted', 'roles', "Role {$role->name} deleted", $role, old: ['permissions' => $role->permissions->pluck('name')->all()]);
            $role->delete();
        });

        return redirect()->route('roles.index')->with('success', 'Role deleted.');
    }
}
