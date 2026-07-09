<?php

use App\Enums\UserRole;
use App\Models\OidcProvider;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

function fakeIdpForRole(OidcProvider $provider, string $email): void
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $pem);
    $d = openssl_pkey_get_details($res);
    $b = fn (string $x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $jwks = ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'kid' => 'k', 'alg' => 'RS256', 'n' => $b($d['rsa']['n']), 'e' => $b($d['rsa']['e'])]]];
    $claims = ['iss' => $provider->issuer, 'aud' => $provider->client_id, 'sub' => 'attacker-sub',
        'email' => $email, 'email_verified' => true, 'nonce' => 'nonce-1', 'exp' => time() + 300, 'iat' => time()];
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

it('refuses to auto-link a federated identity to an admin account by email', function () {
    $admin = User::factory()->create(['email' => 'boss@firma.de', 'role' => UserRole::Admin]);
    fakeIdpForRole($this->provider, 'boss@firma.de');
    $this->withSession(['oidc' => ['state' => 's', 'nonce' => 'nonce-1', 'verifier' => 'v', 'provider' => 'authentik']]);

    $this->get('/auth/oidc/authentik/callback?code=c&state=s')->assertRedirect(route('login'));

    $this->assertGuest();
    expect($this->provider->identities()->where('user_id', $admin->id)->exists())->toBeFalse();
});

it('refuses to auto-link a federated identity to a maintainer account by email', function () {
    $maint = User::factory()->create(['email' => 'maint@firma.de', 'role' => UserRole::Maintainer]);
    fakeIdpForRole($this->provider, 'maint@firma.de');
    $this->withSession(['oidc' => ['state' => 's', 'nonce' => 'nonce-1', 'verifier' => 'v', 'provider' => 'authentik']]);

    $this->get('/auth/oidc/authentik/callback?code=c&state=s')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('still auto-links a regular member account by verified email', function () {
    $member = User::factory()->create(['email' => 'member@firma.de', 'role' => UserRole::Member]);
    fakeIdpForRole($this->provider, 'member@firma.de');
    $this->withSession(['oidc' => ['state' => 's', 'nonce' => 'nonce-1', 'verifier' => 'v', 'provider' => 'authentik']]);

    $this->get('/auth/oidc/authentik/callback?code=c&state=s')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($member);
});
