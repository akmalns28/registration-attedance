<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\DataProvider;
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
        ]]);
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

    public function test_configured_admin_can_sign_in_and_link_their_keycloak_account(): void
    {
        config(['app.initial_admin_email' => 'admin@example.com']);

        $this->seed(AdminUserSeeder::class);
        $this->fakeKeycloakUser();

        $response = $this->withSession(['keycloak.force_login' => true])
            ->get(route('auth.keycloak.callback'));

        $response->assertRedirectToRoute('dashboard');
        $response->assertSessionMissing('error');
        $response->assertSessionMissing('keycloak.force_login');

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('keycloak-user-id', $user->keycloak_id);
        $this->assertSame('Keycloak User', $user->name);
        $this->assertTrue($user->isAdmin());
    }

    public function test_linked_user_can_sign_in_using_their_existing_keycloak_identity(): void
    {
        $user = User::factory()->create([
            'email' => 'previous@example.com',
            'keycloak_id' => 'keycloak-user-id',
            'is_active' => true,
        ]);

        $this->fakeKeycloakUser();

        $this->get(route('auth.keycloak.callback'))
            ->assertRedirectToRoute('dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('previous@example.com', $user->fresh()->email);
    }

    public function test_unapproved_keycloak_account_is_denied_without_creating_a_local_user(): void
    {
        $this->fakeKeycloakUser();

        $this->get(route('auth.keycloak.callback'))
            ->assertRedirectToRoute('login')
            ->assertSessionHas('error', 'Akun Anda belum memiliki akses ke aplikasi.');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_inactive_local_account_is_denied_without_linking_it(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'is_active' => false,
        ]);

        $this->fakeKeycloakUser();

        $this->get(route('auth.keycloak.callback'))
            ->assertRedirectToRoute('login')
            ->assertSessionHas('error', 'Akun Anda belum memiliki akses ke aplikasi.');

        $this->assertGuest();
        $this->assertNull($user->fresh()->keycloak_id);
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
            ->assertSessionHas('error', 'Akun Anda belum memiliki akses ke aplikasi.');

        $this->assertGuest();
        $this->assertSame('another-keycloak-user-id', $user->fresh()->keycloak_id);
    }

    #[DataProvider('unverifiedEmailClaims')]
    public function test_email_verification_failures_have_a_specific_message(mixed $emailVerified): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'is_active' => true,
        ]);

        $this->fakeKeycloakUser(['email_verified' => $emailVerified]);

        $this->withSession(['keycloak.force_login' => true])
            ->get(route('auth.keycloak.callback'))
            ->assertRedirectToRoute('login')
            ->assertSessionHas('error', 'Email akun Keycloak Anda belum terverifikasi. Silakan verifikasi email terlebih dahulu.')
            ->assertSessionHas('keycloak.force_login', true);

        $this->assertGuest();
        $this->assertNull($user->fresh()->keycloak_id);
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
    public function test_missing_identity_claims_are_denied(array $attributes): void
    {
        $this->fakeKeycloakUser($attributes);

        $this->get(route('auth.keycloak.callback'))
            ->assertRedirectToRoute('login')
            ->assertSessionHas('error', 'Akun Anda belum memiliki akses ke aplikasi.');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public static function missingIdentityClaims(): array
    {
        return [
            'missing subject' => [['id' => null]],
            'missing email' => [['email' => null]],
        ];
    }

    private function fakeKeycloakUser(array $attributes = []): void
    {
        Socialite::fake('keycloak', SocialiteUser::fake([
            'id' => 'keycloak-user-id',
            'name' => 'Keycloak User',
            'email' => 'admin@example.com',
            'email_verified' => true,
            ...$attributes,
        ]));
    }
}
