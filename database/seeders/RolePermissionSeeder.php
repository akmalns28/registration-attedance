<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = ['dashboard.view', 'users.view', 'users.manage', 'roles.manage', 'employees.view', 'employees.manage', 'external-participants.view', 'external-participants.manage', 'events.view', 'events.create', 'events.update', 'events.delete', 'registrations.view', 'registrations.manage', 'attendance.view', 'attendance.scan', 'attendance.manual', 'reports.view', 'reports.export', 'departments.view', 'departments.create', 'departments.update', 'departments.delete','departments.export',];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $roles = [
            'super-admin' => $permissions,

            'event-admin' => ['dashboard.view', 'events.view', 'events.create', 'events.update', 'registrations.view', 'registrations.manage', 'attendance.view', 'attendance.scan', 'reports.view', 'reports.export'],

            'panitia' => ['dashboard.view', 'events.view', 'attendance.view', 'attendance.scan'],

            'monitoring' => ['dashboard.view', 'events.view', 'attendance.view', 'reports.view'],
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            // Hanya untuk bootstrap awal.
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($rolePermissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
