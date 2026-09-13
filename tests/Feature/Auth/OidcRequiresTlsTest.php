<?php

// The provider form validated its endpoints with a bare `url` rule, and UrlSafety's scheme
// allowlist deliberately permits `http` because it is built for artifact URLs, where an
// internal mirror without TLS is a real deployment. For an identity provider it is not:
// OidcService POSTs the `client_secret` to the token endpoint and fetches the JWKS the
// id_token signature is checked against, so plain http hands an on-path attacker the
// client secret in cleartext and lets them serve a forged key set.
//
// OIDC Core says the issuer MUST use https, so this refuses nothing the spec allows.

use App\Models\OidcProvider;
use App\Services\Auth\Oidc\OidcDiscovery;
use Illuminate\Support\Facades\Http;

it('refuses an http endpoint on the provider form', function () {
    $payload = [
        'name' => 'Test IdP',
        'slug' => 'test-idp',
        'client_id' => 'client',
        'client_secret' => 'secret',
        'issuer' => 'http://idp.example',
        'authorization_endpoint' => 'http://idp.example/authorize',
        'token_endpoint' => 'http://idp.example/token',
        'jwks_uri' => 'http://idp.example/jwks',
    ];

    $this->actingAs(superAdmin())
        ->post(route('admin.oidc.store'), $payload)
        ->assertSessionHasErrors(['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri']);

    expect(OidcProvider::where('slug', 'test-idp')->exists())->toBeFalse();
});

it('still accepts the same provider over https', function () {
    $payload = [
        'name' => 'Test IdP',
        'slug' => 'test-idp',
        'client_id' => 'client',
        'client_secret' => 'secret',
        'issuer' => 'https://idp.example',
        'authorization_endpoint' => 'https://idp.example/authorize',
        'token_endpoint' => 'https://idp.example/token',
        'jwks_uri' => 'https://idp.example/jwks',
        'userinfo_endpoint' => 'https://idp.example/userinfo',
    ];

    $this->actingAs(superAdmin())
        ->post(route('admin.oidc.store'), $payload)
        ->assertSessionHasNoErrors();

    expect(OidcProvider::where('slug', 'test-idp')->exists())->toBeTrue();
});

it('refuses an http issuer at discovery, before any request is made', function () {
    Http::fake();

    expect(fn () => app(OidcDiscovery::class)->discover('http://idp.example'))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

it('refuses a discovery document that points its endpoints at http', function () {
    // The document is served by the IdP, so its contents are exactly as trustworthy as the
    // IdP — an https issuer advertising an http token endpoint must not downgrade the
    // secret's transport.
    Http::fake(['*/.well-known/openid-configuration' => Http::response([
        'issuer' => 'https://idp.example',
        'authorization_endpoint' => 'https://idp.example/authorize',
        'token_endpoint' => 'http://idp.example/token',
        'jwks_uri' => 'https://idp.example/jwks',
    ])]);

    expect(fn () => app(OidcDiscovery::class)->discover('https://idp.example'))
        ->toThrow(RuntimeException::class, 'token_endpoint');
});

it('refuses an http userinfo endpoint in the discovery document too', function () {
    Http::fake(['*/.well-known/openid-configuration' => Http::response([
        'issuer' => 'https://idp.example',
        'authorization_endpoint' => 'https://idp.example/authorize',
        'token_endpoint' => 'https://idp.example/token',
        'jwks_uri' => 'https://idp.example/jwks',
        'userinfo_endpoint' => 'http://idp.example/userinfo',
    ])]);

    expect(fn () => app(OidcDiscovery::class)->discover('https://idp.example'))
        ->toThrow(RuntimeException::class);
});

it('accepts an all-https discovery document', function () {
    Http::fake(['*/.well-known/openid-configuration' => Http::response([
        'issuer' => 'https://idp.example',
        'authorization_endpoint' => 'https://idp.example/authorize',
        'token_endpoint' => 'https://idp.example/token',
        'jwks_uri' => 'https://idp.example/jwks',
        'userinfo_endpoint' => 'https://idp.example/userinfo',
    ])]);

    expect(app(OidcDiscovery::class)->discover('https://idp.example'))
        ->toHaveKey('token_endpoint', 'https://idp.example/token');
});
