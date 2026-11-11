<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreUserRequest;
use App\Http\Requests\Settings\UpdateUserRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): View
    {
        $users = User::query()->with('roles')->orderBy('name')->get();

        return view('settings.users.index', [
            'users' => $users,
        ]);
    }

    public function create(): View
    {
        return view('settings.users.create', [
            'roles' => $this->getAssignableRoles(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data) {
            $user = new User();

            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->password = null;
            $user->is_active = $data['is_active'];

            $user->save();

            $roles = Role::query()->where('guard_name', 'web')->where('name', '!=', 'super-admin')->whereIn('id', $data['roles'])->get();

            $user->syncRoles($this->getValidatedRoles($data['roles']));
        });

        return redirect()->route('settings.users.index')->with('success', 'Pengguna berhasil ditambahkan.');
    }

    public function edit(User $user): View
    {
        $this->ensureEditable($user);

        return view('settings.users.edit', [
            'user' => $user->load('roles'),
            'roles' => $this->getAssignableRoles(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->ensureEditable($user);

        $data = $request->validated();

        DB::transaction(function () use ($user, $data) {
            $user->name = $data['name'];

            // Email yang sudah terhubung dengan Keycloak
            // tidak dapat diubah melalui User Management.
            if (!$user->keycloak_id) {
                $user->email = $data['email'];
            }

            $user->is_active = $data['is_active'];
            $user->save();

            $roles = Role::query()->where('guard_name', 'web')->where('name', '!=', 'super-admin')->whereIn('id', $data['roles'])->get();

            $user->syncRoles($this->getValidatedRoles($data['roles']));
        });

        return redirect()->route('settings.users.index')->with('success', 'Pengguna berhasil diperbarui.');
    }

    private function getAssignableRoles()
    {
        return Role::query()->where('guard_name', 'web')->where('name', '!=', 'super-admin')->orderBy('name')->get();
    }

    private function ensureEditable(User $user): void
    {
        abort_if($user->id === auth()->id(), 403, 'Anda tidak dapat mengubah akses akun sendiri.');

        abort_if($user->hasRole('super-admin'), 403, 'Akun Super Admin tidak dapat diubah melalui halaman ini.');
    }

    private function getValidatedRoles(array $roleIds)
    {
        return Role::query()->where('guard_name', 'web')->where('name', '!=', 'super-admin')->whereIn('id', $roleIds)->get();
    }
}
