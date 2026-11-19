<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class KeycloakAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.keycloak' => [
            'client_id' => 'registration-test',
            'client_secret' => 'test-client-secret',
            'redirect' => route('auth.keycloak.callback'),
            'base_url' => 'https://sso.example.com',
            'realms' => 'company',
            'sso_roles' => ['event-admin', 'panitia', 'monitoring'],
            'sso_privileged_roles' => [],
        ]]);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_normal_sign_in_allows_the_existing_keycloak_sso_session(): void
    {
        $response = $this->get(route('auth.keycloak.redirect'));

        $response->assertRedirect();

        $url = $response->headers->get('Location');
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

        $this->assertSame('sso.example.com', parse_url($url, PHP_URL_HOST));
        $this->assertSame('/realms/company/protocol/openid-connect/auth', parse_url($url, PHP_URL_PATH));
        $this->assertArrayNotHasKey('prompt', $parameters);
        $this->assertSame('registration-test', $parameters['client_id']);
        $this->assertSame('openid profile email', $parameters['scope']);
        $this->assertNotEmpty($parameters['state']);
        $response->assertSessionHas('state', $parameters['state'])->assertSessionHas('keycloak.nonce', $parameters['nonce']);
    }

    public function test_local_logout_returns_to_login_and_requests_reauthentication_on_subsequent_sign_in_attempts(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->withSession(['private_data' => 'previous-session-data'])
            ->post(route('logout'))
            ->assertRedirectToRoute('login')
            ->assertSessionMissing('private_data')
            ->assertSessionHas('keycloak.force_login', true);

        $this->assertGuest();

        $this->get(route('dashboard'))->assertRedirectToRoute('login');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = $this->get(route('auth.keycloak.redirect'));

            $response->assertRedirect();
            $response->assertSessionHas('keycloak.force_login', true);

            $url = $response->headers->get('Location');
            parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

            $this->assertSame('sso.example.com', parse_url($url, PHP_URL_HOST));
            $this->assertSame('/realms/company/protocol/openid-connect/auth', parse_url($url, PHP_URL_PATH));
            $this->assertSame('login', $parameters['prompt']);
            $this->assertNotEmpty($parameters['state']);
        }
    }

    public function test_allowed_keycloak_user_can_retry_and_is_provisioned(): void
    {
        $this->keycloakHttp([]);
        $this->get(route('auth.keycloak.callback', ['state' => 'stale-state', 'code' => 'stale-code']))
            ->assertRedirectToRoute('login')->assertSessionHas('keycloak.force_login', true);
        $authorization = $this->authorizationParameters($this->withCookie(config('session.cookie'), session()->getId())
            ->get(route('auth.keycloak.redirect')));
        $this->assertSame('login', $authorization['prompt']);
        $csrfToken = session()->token();
        $previousSessionId = session()->getId();
        $this->keycloakHttp($this->signedResponses($authorization['nonce']));

        $response = $this->withCookie(config('session.cookie'), $previousSessionId)->get(route('auth.keycloak.callback', [
            'state' => $authorization['state'], 'code' => 'test-authorization-code',
        ]));

        $response->assertRedirectToRoute('dashboard');
        $response->assertSessionMissing('keycloak.login_error')->assertSessionMissing('keycloak.force_login')
            ->assertSessionMissing('state')->assertSessionMissing('keycloak.nonce');
        $this->assertNotSame($previousSessionId, session()->getId());
        $this->assertNotSame($csrfToken, session()->token());

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('keycloak-user-id', $user->keycloak_id);
        $this->assertSame('Keycloak User', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->password);
        $this->assertSame(['event-admin'], $user->getRoleNames()->all());
    }

    public function test_linked_user_can_sign_in_using_their_existing_keycloak_identity(): void
    {
        $user = User::factory()->create([
            'email' => 'previous@example.com',
            'keycloak_id' => 'keycloak-user-id',
            'is_active' => true,
        ]);

        $user->assignRole('super-admin');
        $this->fakeKeycloakUser(['email' => null, 'keycloak_roles' => ['panitia', 'unknown']]);

        $this->get(route('auth.keycloak.callback'))
            ->assertRedirectToRoute('dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('previous@example.com', $user->fresh()->email);
        $this->assertSame(['panitia'], $user->fresh()->getRoleNames()->all());
    }

    public function test_unapproved_keycloak_account_is_denied_without_creating_a_local_user(): void
    {
        $this->fakeKeycloakUser(['keycloak_roles' => ['super-admin', 'unknown']]);

        $this->get(route('auth.keycloak.callback'))
            ->assertRedirectToRoute('login')
            ->assertSessionHas('keycloak.login_error', 'Akun Anda belum memiliki akses ke aplikasi.');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_inactive_local_account_is_denied_without_reactivation(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'is_active' => false,
            'keycloak_id' => 'keycloak-user-id',
        ]);

        config(['services.keycloak.sso_privileged_roles' => ['super-admin']]);
        $this->fakeKeycloakUser(['keycloak_roles' => ['super-admin']]);

        $this->get(route('auth.keycloak.callback'))
            ->assertRedirectToRoute('login')
            ->assertSessionHas('keycloak.login_error', 'Akun RAMS Anda dinonaktifkan.');

        $this->assertGuest();
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_an_existing_keycloak_binding_cannot_be_replaced_by_matching_email(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'keycloak_id' => 'another-keycloak-user-id',
            'is_active' => true,
        ]);

        $this->fakeKeycloakUser();

        $this->get(route('auth.keycloak.callback'))
            ->assertRedirectToRoute('login')
            ->assertSessionHas('keycloak.login_error', 'Email sudah terdaftar di RAMS. Hubungi administrator untuk menautkan akun.');

        $this->assertGuest();
        $this->assertSame('another-keycloak-user-id', $user->fresh()->keycloak_id);
    }

    public function test_unlinked_account_cannot_be_claimed_by_email(): void
    {
        $user = User::factory()->create(['email' => 'Admin@example.com', 'keycloak_id' => null, 'is_active' => true]);
        $user->assignRole('super-admin');
        $this->fakeKeycloakUser();

        $this->get(route('auth.keycloak.callback'))->assertRedirectToRoute('login')
            ->assertSessionHas('keycloak.login_error', 'Email sudah terdaftar di RAMS. Hubungi administrator untuk menautkan akun.');

        $this->assertGuest();
        $this->assertNull($user->fresh()->keycloak_id);
        $this->assertSame(['super-admin'], $user->fresh()->getRoleNames()->all());
    }

    public function test_ordinary_allowlist_cannot_grant_protected_roles(): void
    {
        Role::create(['name' => 'operations-lead', 'guard_name' => 'web']);
        config([
            'services.keycloak.sso_roles' => ['panitia', 'super-admin', 'operations-lead'],
            'services.keycloak.protected_roles' => ['operations-lead'],
        ]);
        $this->fakeKeycloakUser(['keycloak_roles' => ['panitia', 'super-admin', 'operations-lead']]);

        $this->get(route('auth.keycloak.callback'))->assertRedirectToRoute('dashboard');

        $this->assertSame(['panitia'], User::sole()->getRoleNames()->all());
    }

    public function test_dynamic_matching_requires_an_existing_explicitly_allowed_web_role(): void
    {
        Role::create(['name' => 'check-in-lead', 'guard_name' => 'web']);
        Role::create(['name' => 'api-only', 'guard_name' => 'api']);
        config(['services.keycloak.sso_roles' => ['check-in-lead', 'api-only', 'missing-role']]);
        $this->fakeKeycloakUser(['keycloak_roles' => ['check-in-lead', 'api-only', 'missing-role', 'monitoring']]);

        $this->get(route('auth.keycloak.callback'))->assertRedirectToRoute('dashboard');

        $this->assertSame(['check-in-lead'], User::sole()->getRoleNames()->all());
        $this->assertDatabaseMissing('roles', ['name' => 'missing-role']);
        $this->assertDatabaseMissing('roles', ['name' => 'api-only', 'guard_name' => 'web']);
        $this->assertDatabaseMissing('permissions', ['name' => 'missing-role']);
    }

    public function test_privileged_client_role_is_denied_until_allowlisted_then_can_provision_on_retry(): void
    {
        $accessClaims = ['resource_access' => ['registration-test' => ['roles' => ['super-admin']]]];
        $flow = $this->authorizationParameters($this->get(route('auth.keycloak.redirect')));
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->keycloakHttp($this->signedResponses($flow['nonce'], accessClaims: $accessClaims));

        $this->get(route('auth.keycloak.callback', [
            'state' => $flow['state'], 'code' => 'privileged-login',
        ]))->assertRedirectToRoute('login')->assertSessionHas('keycloak.login_error', 'Akun Anda belum memiliki akses ke aplikasi.');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);

        config(['services.keycloak.sso_privileged_roles' => ['super-admin']]);
        $retry = $this->authorizationParameters($this->get(route('auth.keycloak.redirect')));
        $this->assertSame('login', $retry['prompt']);
        $this->assertNotSame($flow['state'], $retry['state']);
        $this->assertNotSame($flow['nonce'], $retry['nonce']);
        $previousSessionId = session()->getId();
        $this->keycloakHttp($this->signedResponses($retry['nonce'], accessClaims: $accessClaims));

        $this->get(route('auth.keycloak.callback', [
            'state' => $retry['state'], 'code' => 'privileged-retry',
        ]))->assertRedirectToRoute('dashboard');

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->is_active);
        $this->assertSame(['super-admin'], $user->getRoleNames()->all());
        $this->assertNotSame($previousSessionId, session()->getId());
    }

    public function test_client_source_ignores_other_clients_realm_roles_and_userinfo_roles(): void
    {
        $flow = $this->authorizationParameters($this->get(route('auth.keycloak.redirect')));
        $this->keycloakHttp($this->signedResponses($flow['nonce'], [
            'resource_access' => ['registration-test' => ['roles' => ['panitia']]],
        ], [
            'realm_access' => ['roles' => ['panitia']],
            'resource_access' => ['other-client' => ['roles' => ['panitia']]],
        ]));

        $this->withCookie(config('session.cookie'), session()->getId())->get(route('auth.keycloak.callback', [
            'state' => $flow['state'], 'code' => 'client-login',
        ]))->assertRedirectToRoute('login')->assertSessionHas('keycloak.login_error', 'Akun Anda belum memiliki akses ke aplikasi.');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('unverifiedEmailClaims')]
    public function test_email_verification_is_not_required(mixed $emailVerified): void
    {
        $this->fakeKeycloakUser(['email_verified' => $emailVerified]);

        $this->get(route('auth.keycloak.callback'))->assertRedirectToRoute('dashboard');

        $this->assertAuthenticated();
    }

    public static function unverifiedEmailClaims(): array
    {
        return [
            'unverified' => [false],
            'missing' => [null],
            'invalid string' => ['true'],
        ];
    }

    #[DataProvider('missingIdentityClaims')]
    public function test_missing_identity_claims_are_denied(array $attributes, string $message): void
    {
        $this->fakeKeycloakUser($attributes);

        $this->get(route('auth.keycloak.callback'))
            ->assertRedirectToRoute('login')
            ->assertSessionHas('keycloak.login_error', $message);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public static function missingIdentityClaims(): array
    {
        return [
            'missing subject' => [['id' => null], 'Akun Anda belum memiliki akses ke aplikasi.'],
            'missing email' => [['email' => null], 'Email Keycloak yang valid diperlukan untuk membuat akun RAMS.'],
        ];
    }

    #[DataProvider('failedCallbacks')]
    public function test_failed_callback_consumes_only_the_transaction_and_retry_starts_a_fresh_keycloak_login(string $failure, string $message): void
    {
        $this->withoutVite();
        $intended = route('dashboard');
        $first = $this->authorizationParameters($this->withSession(['url.intended' => $intended, 'unrelated' => 'preserved'])
            ->get(route('auth.keycloak.redirect')));
        $csrfToken = session()->token();
        $query = ['state' => $first['state'], 'code' => 'first-code'];
        $responses = [];
        switch ($failure) {
            case 'state':
                $query['state'] = 'an-invalid-state';
                break;
            case 'missing code':
                unset($query['code']);
                break;
            case 'cancellation':
                $query = ['state' => $first['state'], 'error' => 'access_denied', 'error_description' => 'Provider-only detail'];
                break;
            case 'expired code':
                $responses = [new Response(400, [], '{"error":"invalid_grant"}')];
                break;
            case 'network':
                $responses = [new ConnectException('Network unavailable', new Request('POST', 'https://sso.example.com'))];
                break;
            case 'nonce':
                $responses = $this->signedResponses('wrong-nonce');
                break;
            case 'audience':
                $responses = $this->signedResponses($first['nonce'], ['aud' => 'another-client']);
                break;
            case 'subject':
                $responses = $this->signedResponses($first['nonce'], ['sub' => 'another-user'], ['sub' => 'another-user']);
                break;
            case 'access client':
                $responses = $this->signedResponses($first['nonce'], accessClaims: ['azp' => 'other-client']);
                break;
            case 'access subject':
                $responses = $this->signedResponses($first['nonce'], accessClaims: ['sub' => 'another-user']);
                break;
            case 'access key':
                $responses = $this->signedResponses($first['nonce'], accessKeyId: 'unknown-key');
                break;
            case 'access expiry':
                $responses = $this->signedResponses($first['nonce'], accessClaims: ['exp' => time() - 60]);
                break;
        }
        $keycloak = $this->keycloakHttp($responses);
        $this->withCookie(config('session.cookie'), session()->getId());

        $this->get(route('auth.keycloak.callback', $query))->assertRedirectToRoute('login')
            ->assertSessionHas('keycloak.login_error', $message)->assertSessionHas('keycloak.force_login', true)
            ->assertSessionMissing('state')->assertSessionMissing('keycloak.nonce')->assertSessionMissing('error')
            ->assertSessionHas('_token', $csrfToken)->assertSessionHas('url.intended', $intended)
            ->assertSessionHas('unrelated', 'preserved');
        $this->assertGuest();
        if (in_array($failure, ['state', 'missing code', 'cancellation'], true)) {
            $this->assertNull($keycloak->getLastRequest());
        }
        if (in_array($failure, ['nonce', 'audience'], true) || str_starts_with($failure, 'access ')) {
            $this->assertStringEndsWith('/certs', $keycloak->getLastRequest()->getUri()->getPath());
        }

        $login = $this->get(route('login'))->assertOk()
            ->assertSee('Login ulang dengan SSO')->assertSee('href="'.route('auth.keycloak.redirect').'"', false)
            ->assertDontSee('Provider-only detail');
        $this->assertSame(1, substr_count($login->getContent(), $message));
        $this->get(route('login'))->assertOk()->assertDontSee($message);
        $retry = $this->authorizationParameters($this->get(route('auth.keycloak.redirect'))
            ->assertSessionMissing('keycloak.login_error'));
        $this->assertNotSame($first['state'], $retry['state']);
        $this->assertNotSame($first['nonce'], $retry['nonce']);
        $this->assertSame('login', $retry['prompt']);
    }

    public static function failedCallbacks(): array
    {
        $expired = 'Sesi login SSO berakhir. Silakan login kembali.';

        return [
            'invalid state' => ['state', $expired],
            'missing authorization code' => ['missing code', $expired],
            'expired authorization code' => ['expired code', $expired],
            'invalid nonce' => ['nonce', $expired],
            'ID token for another client' => ['audience', $expired],
            'UserInfo subject mismatch' => ['subject', $expired],
            'access token for another client' => ['access client', $expired],
            'access token for another subject' => ['access subject', $expired],
            'access token with an unknown signing key' => ['access key', $expired],
            'expired access token' => ['access expiry', $expired],
            'cancelled by user' => ['cancellation', 'Login SSO dibatalkan. Silakan coba lagi.'],
            'network error' => ['network', 'Layanan SSO sedang tidak dapat diakses. Silakan coba lagi.'],
        ];
    }

    public function test_exception_after_authentication_starts_rolls_back_the_partial_login(): void
    {
        $this->fakeKeycloakUser();
        $exception = new RuntimeException('Login listener failed');
        Exceptions::fake();
        Event::listen(Login::class, static function () use ($exception): void {
            throw $exception;
        });
        $loginKey = Auth::guard('web')->getName();

        $this->withSession(['url.intended' => route('dashboard')])->get(route('auth.keycloak.callback'))
            ->assertRedirectToRoute('login')->assertSessionMissing($loginKey)->assertSessionMissing('password_hash_web')
            ->assertSessionHas('keycloak.force_login', true)->assertSessionHas('url.intended', route('dashboard'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('model_has_roles', 0);
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
    }

    public function test_login_redirect_configuration_failure_recovers_on_retry(): void
    {
        $issuer = config('services.keycloak.base_url');
        config(['services.keycloak.base_url' => null]);
        Exceptions::fake();

        $this->get(route('auth.keycloak.redirect'))->assertRedirectToRoute('login')
            ->assertSessionHas('keycloak.login_error', 'Autentikasi SSO gagal. Silakan coba lagi.')
            ->assertSessionMissing('state')->assertSessionMissing('keycloak.nonce');

        config(['services.keycloak.base_url' => $issuer]);
        Socialite::forgetDrivers();
        $retry = $this->authorizationParameters($this->get(route('auth.keycloak.redirect')));
        $this->assertSame('login', $retry['prompt']);
    }

    public function test_https_proxy_preserves_secure_session_cookies_and_public_login_and_logout_urls(): void
    {
        config(['trustedproxy.proxies' => '192.0.2.10', 'session.secure' => true,
            'services.keycloak.redirect' => '/auth/keycloak/callback']);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->withHeaders([
            'X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', 'X-Forwarded-Host' => 'untrusted.example.com',
        ]);
        $start = $this->get('http://attendance.example.com/auth/keycloak/redirect');
        $this->assertSame('https://attendance.example.com/auth/keycloak/callback', $this->authorizationParameters($start)['redirect_uri']);
        $this->assertTrue($start->getCookie(config('session.cookie'), false)->isSecure());

        $response = $this->actingAs(User::factory()->create(['is_active' => false]))
            ->post('http://attendance.example.com/logout')->assertRedirect('https://attendance.example.com/login')
            ->assertSessionHas('keycloak.force_login', true);
        $cookie = $response->getCookie(config('session.cookie'), false);
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertGuest();
    }

    private function authorizationParameters(TestResponse $response): array
    {
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertSame('https://sso.example.com/realms/company/protocol/openid-connect/auth', strtok($location, '?'));
        parse_str(parse_url($location, PHP_URL_QUERY), $parameters);

        return $parameters;
    }

    private function keycloakHttp(array $responses): MockHandler
    {
        $handler = new MockHandler($responses);
        Socialite::driver('keycloak')->setHttpClient(new Client(['handler' => HandlerStack::create($handler)]));

        return $handler;
    }

    private function signedResponses(string $nonce, array $claims = [], array $accessClaims = [], string $accessKeyId = 'test-key'): array
    {
        $opensslConfig = tempnam(sys_get_temp_dir(), 'keycloak-test-');
        $this->assertNotFalse($opensslConfig);
        try {
            $key = openssl_pkey_new([
                'config' => $opensslConfig, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048,
            ]);
        } finally {
            unlink($opensslConfig);
        }
        $this->assertNotFalse($key);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $idToken = JWT::encode([
            'iss' => 'https://sso.example.com/realms/company', 'aud' => 'registration-test',
            'sub' => 'keycloak-user-id', 'nonce' => $nonce, 'iat' => time(), 'exp' => time() + 300,
            ...$claims,
        ], $key, 'RS256', 'test-key');

        $accessToken = JWT::encode([
            'iss' => 'https://sso.example.com/realms/company', 'azp' => 'registration-test', 'aud' => 'account',
            'typ' => 'Bearer', 'sub' => 'keycloak-user-id', 'iat' => time(), 'exp' => time() + 300,
            'resource_access' => ['registration-test' => ['roles' => ['event-admin']]],
            ...$accessClaims,
        ], $key, 'RS256', $accessKeyId);

        return [
            new Response(200, [], json_encode(['access_token' => $accessToken, 'id_token' => $idToken], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['keys' => [[
                'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'test-key',
                'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e']),
            ]]], JSON_THROW_ON_ERROR)),
            new Response(200, [], '{"sub":"keycloak-user-id","name":"Keycloak User","email":"admin@example.com","keycloak_roles":["panitia"]}'),
        ];
    }

    private function fakeKeycloakUser(array $attributes = []): void
    {
        Socialite::fake('keycloak', SocialiteUser::fake([
            'id' => 'keycloak-user-id',
            'name' => 'Keycloak User',
            'email' => 'admin@example.com',
            'keycloak_roles' => ['panitia'],
            ...$attributes,
        ]));
    }
}
