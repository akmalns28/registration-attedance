<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class KeycloakController extends Controller
{
    /**
     * Redirect user to Keycloak.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $parameters = $request->session()->get('keycloak.force_login', false)
            ? ['prompt' => 'login']
            : [];

        return Socialite::driver('keycloak')
            ->scopes(['openid', 'profile', 'email'])
            ->with($parameters)
            ->redirect();
    }

    /**
     * Handle callback from Keycloak.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $keycloakUser = Socialite::driver('keycloak')->user();

            $keycloakId = $keycloakUser->getId();
            $email = $keycloakUser->getEmail();

            if (! $keycloakId || ! $email) {
                return $this->denyAccess();
            }

            $claims = $keycloakUser->user ?? [];

            if (($claims['email_verified'] ?? false) !== true) {
                return $this->denyAccess(
                    'Email akun Keycloak Anda belum terverifikasi. Silakan verifikasi email terlebih dahulu.'
                );
            }

            /*
             * Access policy:
             * Only previously authorized local users
             * are allowed to sign in.
             */
            $user = User::query()
                ->where('keycloak_id', $keycloakId)
                ->first();

            if (! $user) {
                $user = User::query()
                    ->whereNull('keycloak_id')
                    ->where('email', $email)
                    ->first();
            }

            if (! $user || ! $user->canAccessApplication()) {
                return $this->denyAccess();
            }

            /*
             * Bind local account to Keycloak identity.
             * Existing bindings cannot be overwritten.
             */
            if (! $user->keycloak_id) {
                $user->keycloak_id = $keycloakId;
            }

            $user->name = $keycloakUser->getName()
                ?: $user->name;

            $user->save();

            Auth::login($user);

            $request->session()->regenerate();
            $request->session()->forget('keycloak.force_login');

            return redirect()->intended(
                route('dashboard')
            );

        } catch (Throwable $exception) {

            report($exception);

            return redirect()
                ->route('login')
                ->with(
                    'error',
                    'Autentikasi SSO gagal. Silakan coba kembali.'
                );
        }
    }

    /**
     * Logout from local Laravel session.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('keycloak.force_login', true);

        return redirect()->route('login');
    }

    private function denyAccess(string $message = 'Akun Anda belum memiliki akses ke aplikasi.'): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->with(
                'error',
                $message
            );
    }
}
