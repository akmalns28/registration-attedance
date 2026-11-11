<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('app.initial_admin_email');

        if (empty($email)) {
            throw new RuntimeException(
                'INITIAL_ADMIN_EMAIL belum dikonfigurasi.'
            );
        }

        $this->call(RolePermissionSeeder::class);

        $user = User::firstOrNew([
            'email' => $email,
        ]);

        if (! $user->exists) {
            $user->name = 'Administrator';
            $user->password = null;
        }

        $user->is_active = true;

        $user->save();
        $user->assignRole('super-admin');
    }
}
