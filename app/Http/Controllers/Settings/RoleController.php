<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreRoleRequest;
use App\Http\Requests\Settings\UpdateRoleRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Display a listing of roles.
     */
    public function index(): View
    {
        $roles = Role::query()->where('guard_name', 'web')->withCount('permissions')->orderBy('name')->get();

        return view('settings.roles.index', compact('roles'));
    }

    /**
     * Show the form for creating a new role.
     */
    public function create(): View
    {
        return view('settings.roles.create', [
            'permissions' => $this->getPermissions(),
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($data['permissions'] ?? []);
        });

        return redirect()->route('settings.roles.index')->with('success', 'Role berhasil ditambahkan.');
    }

    /**
     * Show the form for editing the specified role.
     */
    public function edit(Role $role): View
    {
        $this->ensureEditable($role);

        $role->load('permissions');

        return view('settings.roles.edit', [
            'role' => $role,
            'permissions' => $this->getPermissions(),
        ]);
    }

    /**
     * Update the specified role.
     */
    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->ensureEditable($role);

        $data = $request->validated();

        DB::transaction(function () use ($role, $data) {
            $role->update([
                'name' => $data['name'],
            ]);

            $role->syncPermissions($data['permissions'] ?? []);
        });

        return redirect()->route('settings.roles.index')->with('success', 'Role berhasil diperbarui.');
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Role $role): RedirectResponse
    {
        $this->ensureEditable($role);

        $assignedUsers = DB::table('model_has_roles')->where('role_id', $role->id)->exists();

        if ($assignedUsers) {
            return back()->with('error', 'Role tidak dapat dihapus karena masih digunakan.');
        }

        $role->delete();

        return redirect()->route('settings.roles.index')->with('success', 'Role berhasil dihapus.');
    }

    /**
     * Get permissions grouped by module.
     */
    private function getPermissions()
    {
        return Permission::query()->where('guard_name', 'web')->orderBy('name')->get()->groupBy(fn($permission) => explode('.', $permission->name)[0]);
    }

    /**
     * Prevent modifications to protected roles.
     */
    private function ensureEditable(Role $role): void
    {
        abort_unless($role->guard_name === 'web', 404);

        abort_if($role->name === 'super-admin', 403, 'Role Super Admin tidak dapat dimodifikasi.');
    }
}
