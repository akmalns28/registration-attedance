<?php

namespace App\Services;

use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Socialite\Two\InvalidStateException;
use LogicException;
use SocialiteProviders\Keycloak\Provider;
use stdClass;
use UnexpectedValueException;

class KeycloakProvider extends Provider
{
    public const NONCE_SESSION_KEY = 'keycloak.nonce';

    private ?stdClass $identity = null;

    private array $clientRoles = [];

    public function setRequest(Request $request): static
    {
        $this->user = null;
        $this->credentialsResponseBody = null;
        $this->identity = null;
        $this->clientRoles = [];

        return parent::setRequest($request);
    }

    protected function getAuthUrl(mixed $state): string
    {
        if (! $this->clientId || ! filter_var($this->getBaseUrl(), FILTER_VALIDATE_URL)
            || ! filter_var($this->redirectUrl, FILTER_VALIDATE_URL)) {
            throw new LogicException('Keycloak client ID and absolute URLs are required.');
        }

        return parent::getAuthUrl($state);
    }

    protected function getCodeFields(mixed $state = null): array
    {
        $nonce = Str::random(40);
        $this->request->session()->put(self::NONCE_SESSION_KEY, $nonce);

        return [...parent::getCodeFields($state), 'nonce' => $nonce];
    }

    protected function hasInvalidState(): bool
    {
        return ! is_string($this->request->query('state')) || parent::hasInvalidState();
    }

    public function getAccessTokenResponse(#[\SensitiveParameter] mixed $code): array
    {
        $nonce = $this->request->session()->pull(self::NONCE_SESSION_KEY);
        if (! is_string($code) || $code === '' || ! is_string($nonce) || $nonce === '') {
            throw new InvalidStateException;
        }

        $tokens = parent::getAccessTokenResponse($code);
        if (! is_string($tokens['id_token'] ?? null) || ! is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === '') {
            throw new InvalidStateException;
        }

        $signingKeys = $this->signingKeys();
        $identity = $this->validatedClaims($tokens['id_token'], $signingKeys);
        $access = $this->validatedClaims($tokens['access_token'], $signingKeys);

        if ((array) ($identity->aud ?? []) !== [$this->clientId]
            || (isset($identity->azp) && $identity->azp !== $this->clientId)
            || ! is_string($identity->nonce ?? null) || ! hash_equals($nonce, $identity->nonce)
            || ($access->typ ?? null) !== 'Bearer' || ($access->azp ?? null) !== $this->clientId
            || $access->sub !== $identity->sub) {
            throw new InvalidStateException;
        }

        $this->identity = $identity;
        $roles = $access->resource_access->{$this->clientId}->roles ?? [];
        $this->clientRoles = is_array($roles) ? array_values(array_filter($roles, is_string(...))) : [];

        return $tokens;
    }

    protected function getUserByToken(#[\SensitiveParameter] mixed $token): array
    {
        $profile = parent::getUserByToken($token);
        if ($this->identity === null || ($profile['sub'] ?? null) !== $this->identity->sub) {
            throw new InvalidStateException;
        }

        return [...$profile, 'keycloak_roles' => $this->clientRoles];
    }

    /** @return array<string, Key> */
    private function signingKeys(): array
    {
        $issuer = $this->getBaseUrl();
        $response = $this->getHttpClient()->get($issuer.'/protocol/openid-connect/certs');
        $keySet = json_decode((string) $response->getBody(), true);
        if (! is_array($keySet['keys'] ?? null)) {
            throw new InvalidStateException;
        }

        $signingKeys = array_filter($keySet['keys'], fn (mixed $key): bool => is_array($key)
            && ($key['kty'] ?? null) === 'RSA'
            && ($key['use'] ?? 'sig') === 'sig'
            && ($key['alg'] ?? 'RS256') === 'RS256');

        try {
            return JWK::parseKeySet(['keys' => $signingKeys], 'RS256');
        } catch (DomainException|InvalidArgumentException|UnexpectedValueException) {
            throw new InvalidStateException;
        }
    }

    /** @param array<string, Key> $signingKeys */
    private function validatedClaims(#[\SensitiveParameter] string $token, array $signingKeys): stdClass
    {
        try {
            $claims = JWT::decode($token, $signingKeys);
        } catch (DomainException|InvalidArgumentException|UnexpectedValueException) {
            throw new InvalidStateException;
        }

        if (($claims->iss ?? null) !== $this->getBaseUrl()
            || ! is_int($claims->exp ?? null) || ! is_int($claims->iat ?? null)
            || ! is_string($claims->sub ?? null) || $claims->sub === '') {
            throw new InvalidStateException;
        }

        return $claims;
    }
}
