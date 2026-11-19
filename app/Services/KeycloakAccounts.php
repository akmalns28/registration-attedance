<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as KeycloakUser;
use RuntimeException;
use Spatie\Permission\Models\Role;

class KeycloakAccounts
{
    public function synchronize(KeycloakUser $identity): User
    {
        $subject = $identity->getId();
        $roles = $this->allowedRoles($identity->user['keycloak_roles'] ?? []);
        if (! is_string($subject) || $subject === '' || strlen($subject) > 255 || $roles === []) {
            throw new AuthorizationException('Akun Anda belum memiliki akses ke aplikasi.');
        }

        $user = User::query()->where('keycloak_id', $subject)->lockForUpdate()->first();
        if ($user && $user->keycloak_id !== $subject) {
            throw new AuthorizationException('Identitas Keycloak tidak cocok dengan akun RAMS.');
        }

        if ($user && ! $user->canAccessApplication()) {
            throw new AuthorizationException('Akun RAMS Anda dinonaktifkan.');
        }

        if (! $user) {
            $email = strtolower(trim((string) $identity->getEmail()));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                throw new AuthorizationException('Email Keycloak yang valid diperlukan untuk membuat akun RAMS.');
            }

            if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw new AuthorizationException('Email sudah terdaftar di RAMS. Hubungi administrator untuk menautkan akun.');
            }

            $user = new User;
            $user->keycloak_id = $subject;
            $user->name = Str::substr(trim((string) $identity->getName()) ?: $email, 0, 255);
            $user->email = $email;
            $user->is_active = true;
            if (! $user->save()) {
                throw new RuntimeException('Could not provision the Keycloak account.');
            }
        }

        sort($roles);
        if ($user->getRoleNames()->sort()->values()->all() !== $roles) {
            $user->syncRoles($roles);
        }

        return $user;
    }

    /** @param array<array-key, mixed> $externalRoles
     * @return list<string>
     */
    private function allowedRoles(array $externalRoles): array
    {
        $privileged = config('services.keycloak.sso_privileged_roles', []);
        $protected = ['super-admin', ...config('services.keycloak.protected_roles', []), ...$privileged];
        $ordinary = array_diff(config('services.keycloak.sso_roles', []), $protected);
        $allowed = array_intersect(array_filter($externalRoles, is_string(...)), [...$ordinary, ...$privileged]);

        if ($allowed === []) {
            return [];
        }

        $existing = Role::query()->where('guard_name', 'web')->whereIn('name', $allowed)->pluck('name')->all();

        return array_values(array_intersect($existing, $allowed));
    }
}
