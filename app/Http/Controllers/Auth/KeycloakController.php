<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\KeycloakAccounts;
use App\Services\KeycloakProvider;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class KeycloakController extends Controller
{
    private const RETRY_MESSAGE = 'Autentikasi SSO gagal. Silakan coba lagi.';

    private const EXPIRED_MESSAGE = 'Sesi login SSO berakhir. Silakan login kembali.';

    /**
     * Redirect user to Keycloak.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $session = $request->session();
        $session->forget('keycloak.login_error');
        $parameters = $session->get('keycloak.force_login', false)
            ? ['prompt' => 'login']
            : [];

        try {
            return Socialite::driver('keycloak')
                ->setRequest($request)
                ->scopes(['openid', 'profile', 'email'])
                ->with($parameters)
                ->redirect()
                ->header('Cache-Control', 'no-store, private');
        } catch (Throwable $exception) {
            $response = $this->failedLogin($request, self::RETRY_MESSAGE);
            report($exception);

            return $response;
        }
    }

    /**
     * Handle callback from Keycloak.
     */
    public function callback(Request $request, KeycloakAccounts $accounts): RedirectResponse
    {
        try {
            if ($request->query->has('error')) {
                $expectedState = $request->session()->get('state');
                $state = $request->query('state');
                if (! is_string($expectedState) || $expectedState === '' || ! is_string($state) || ! hash_equals($expectedState, $state)) {
                    throw new InvalidStateException;
                }

                return $this->failedLogin($request, $request->query('error') === 'access_denied'
                    ? 'Login SSO dibatalkan. Silakan coba lagi.'
                    : self::RETRY_MESSAGE);
            }

            $keycloakUser = Socialite::driver('keycloak')->setRequest($request)->with([])->user();

            DB::transaction(function () use ($accounts, $keycloakUser): void {
                Auth::guard('web')->login($accounts->synchronize($keycloakUser));
            });
            $request->session()->forget('keycloak.force_login');

            return redirect()->intended(route('dashboard'))->header('Cache-Control', 'no-store, private');
        } catch (AuthorizationException $exception) {
            return $this->failedLogin($request, $exception->getMessage());
        } catch (InvalidStateException) {
            return $this->failedLogin($request, self::EXPIRED_MESSAGE);
        } catch (TransferException $exception) {
            $response = $exception instanceof RequestException ? $exception->getResponse() : null;
            if ($response?->getStatusCode() === 400 && (json_decode((string) $response->getBody(), true)['error'] ?? null) === 'invalid_grant') {
                return $this->failedLogin($request, self::EXPIRED_MESSAGE);
            }

            Log::warning('Keycloak authentication request failed.', [
                'type' => $exception::class,
                'status' => $response?->getStatusCode(),
            ]);

            return $this->failedLogin($request, 'Layanan SSO sedang tidak dapat diakses. Silakan coba lagi.');
        } catch (Throwable $exception) {
            $response = $this->failedLogin($request, self::RETRY_MESSAGE);
            report($exception);

            return $response;
        }
    }

    /**
     * Logout from local Laravel session.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('keycloak.force_login', true);

        return redirect()->route('login')->header('Cache-Control', 'no-store, private');
    }

    private function failedLogin(Request $request, string $message): RedirectResponse
    {
        $guard = Auth::guard('web');
        $request->session()->forget(['state', KeycloakProvider::NONCE_SESSION_KEY, $guard->getName(), 'password_hash_web']);
        $guard->forgetUser();
        $request->session()->put('keycloak.force_login', true);

        return redirect()->route('login')->header('Cache-Control', 'no-store, private')->with('keycloak.login_error', $message);
    }
}
