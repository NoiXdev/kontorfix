<?php

use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

/**
 * Fakt IdP-Token+JWKS mit einer bestimmten nonce (die aus der echten Session stammt).
 */
function fakeIdpWithNonce(OidcProvider $provider, string $nonce, array $claimOverrides = []): void
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $pem);
    $d = openssl_pkey_get_details($res);
    $b = fn (string $x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $jwks = ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'kid' => 'k', 'alg' => 'RS256', 'n' => $b($d['rsa']['n']), 'e' => $b($d['rsa']['e'])]]];
    $claims = array_merge([
        'iss' => $provider->issuer, 'aud' => $provider->client_id, 'sub' => 'sub-e2e',
        'email' => 'e2e@idp.test', 'email_verified' => true, 'nonce' => $nonce,
        'exp' => time() + 300, 'iat' => time(),
    ], $claimOverrides);
    $idToken = JWT::encode($claims, $pem, 'RS256', 'k');
    Http::fake([
        $provider->token_endpoint => Http::response(['id_token' => $idToken, 'access_token' => 'at']),
        $provider->jwks_uri => Http::response($jwks),
    ]);
}

beforeEach(function () {
    $this->provider = OidcProvider::factory()->create([
        'slug' => 'authentik', 'enabled' => true, 'issuer' => 'https://idp.test', 'client_id' => 'client-abc',
        'authorization_endpoint' => 'https://idp.test/authorize', 'token_endpoint' => 'https://idp.test/token', 'jwks_uri' => 'https://idp.test/jwks',
    ]);
});

it('runs the full redirect -> callback chain against the live session state', function () {
    $user = User::factory()->create(['email' => 'e2e@idp.test']);

    // 1) Redirect erzeugt state/nonce/verifier in der Session.
    $redirect = $this->get('/auth/oidc/authentik/redirect');
    $redirect->assertRedirect();
    expect($redirect->headers->get('Location'))->toStartWith('https://idp.test/authorize?');

    $state = session('oidc.state');
    $nonce = session('oidc.nonce');
    expect($state)->not->toBeNull();
    expect($nonce)->not->toBeNull();

    // 2) IdP signiert ein id_token mit genau dieser nonce.
    fakeIdpWithNonce($this->provider, $nonce);

    // 3) Callback mit dem echten Session-state → eingeloggt, Identität verknüpft.
    $this->get('/auth/oidc/authentik/callback?code=live-code&state='.$state)
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($this->provider->identities()->where('subject', 'sub-e2e')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('provisions a fresh user end-to-end when registration is allowed', function () {
    $org = Organization::factory()->create();
    $this->provider->update(['allow_registration' => true, 'default_organization_id' => $org->id, 'default_role' => 'member']);

    $this->get('/auth/oidc/authentik/redirect');
    $nonce = session('oidc.nonce');
    $state = session('oidc.state');
    fakeIdpWithNonce($this->provider, $nonce, ['sub' => 'sub-new', 'email' => 'fresh@idp.test']);

    $this->get('/auth/oidc/authentik/callback?code=c&state='.$state)->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    expect(User::where('email', 'fresh@idp.test')->exists())->toBeTrue();
});
