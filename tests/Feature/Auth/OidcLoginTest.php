<?php

use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

function fakeIdp(OidcProvider $provider, array $claimOverrides = []): void
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $pem);
    $d = openssl_pkey_get_details($res);
    $b = fn (string $x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $jwks = ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'kid' => 'k', 'alg' => 'RS256', 'n' => $b($d['rsa']['n']), 'e' => $b($d['rsa']['e'])]]];
    $claims = array_merge(['iss' => $provider->issuer, 'aud' => $provider->client_id, 'sub' => 'sub-1',
        'email' => 'sso@idp.test', 'email_verified' => true, 'nonce' => 'nonce-1', 'exp' => time() + 300, 'iat' => time()], $claimOverrides);
    $idToken = JWT::encode($claims, $pem, 'RS256', 'k');
    Http::fake([
        $provider->token_endpoint => Http::response(['id_token' => $idToken, 'access_token' => 'at']),
        $provider->jwks_uri => Http::response($jwks),
    ]);
}

function primeSession($test, OidcProvider $provider): void
{
    $test->withSession(['oidc' => ['state' => 'state-1', 'nonce' => 'nonce-1', 'verifier' => 'ver-1', 'provider' => $provider->slug]]);
}

beforeEach(function () {
    // SSO login is a post-installation flow; on an instance with no account at all
    // the setup wizard takes precedence over every other web route.
    $this->instanceAlreadySetUp();

    $this->provider = OidcProvider::factory()->create([
        'slug' => 'authentik', 'enabled' => true, 'issuer' => 'https://idp.test', 'client_id' => 'client-abc',
        'authorization_endpoint' => 'https://idp.test/authorize', 'token_endpoint' => 'https://idp.test/token', 'jwks_uri' => 'https://idp.test/jwks',
    ]);
});

it('redirects to the identity provider with a stored state', function () {
    $res = $this->get('/auth/oidc/authentik/redirect');
    $res->assertRedirect();
    expect($res->headers->get('Location'))->toStartWith('https://idp.test/authorize?');
    expect(session('oidc.provider'))->toBe('authentik');
});

it('logs in an existing user matched by verified email and links the identity', function () {
    $user = User::factory()->create(['email' => 'sso@idp.test']);
    fakeIdp($this->provider);
    primeSession($this, $this->provider);

    $this->get('/auth/oidc/authentik/callback?code=the-code&state=state-1')
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($this->provider->identities()->where('subject', 'sub-1')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('logs in a returning user via the stored identity', function () {
    $user = User::factory()->create(['email' => 'changed@elsewhere.test']);
    $this->provider->identities()->create(['user_id' => $user->id, 'subject' => 'sub-1']);
    fakeIdp($this->provider);
    primeSession($this, $this->provider);

    $this->get('/auth/oidc/authentik/callback?code=c&state=state-1')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

it('rejects an unknown user when registration is disabled', function () {
    fakeIdp($this->provider);
    primeSession($this, $this->provider);

    $this->get('/auth/oidc/authentik/callback?code=c&state=state-1')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('provisions a new user when the provider allows registration', function () {
    $org = Organization::factory()->create();
    $this->provider->update(['allow_registration' => true, 'default_organization_id' => $org->id, 'default_role' => 'member']);
    fakeIdp($this->provider);
    primeSession($this, $this->provider);

    $this->get('/auth/oidc/authentik/callback?code=c&state=state-1')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();
    expect(User::where('email', 'sso@idp.test')->exists())->toBeTrue();
});

it('rejects a callback whose state does not match the session', function () {
    fakeIdp($this->provider);
    primeSession($this, $this->provider);

    $this->get('/auth/oidc/authentik/callback?code=c&state=WRONG')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('does not require the totp step even if the user has 2fa', function () {
    $user = User::factory()->create(['email' => 'sso@idp.test']);
    $user->forceFill(['two_factor_secret' => app(TwoFactorAuthenticator::class)->generateSecret(), 'two_factor_confirmed_at' => now()])->save();
    fakeIdp($this->provider);
    primeSession($this, $this->provider);

    $this->get('/auth/oidc/authentik/callback?code=c&state=state-1')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});
