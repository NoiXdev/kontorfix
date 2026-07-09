<?php

use App\Models\OidcProvider;
use App\Services\Auth\Oidc\OidcService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** @return array{provider:OidcProvider,idToken:string,jwks:array} */
function oidcFixture(array $claimOverrides = []): array
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $privatePem);
    $details = openssl_pkey_get_details($res);

    $b64url = fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    $jwks = ['keys' => [[
        'kty' => 'RSA', 'use' => 'sig', 'kid' => 'test-key', 'alg' => 'RS256',
        'n' => $b64url($details['rsa']['n']), 'e' => $b64url($details['rsa']['e']),
    ]]];

    $provider = OidcProvider::factory()->create([
        'issuer' => 'https://idp.test', 'client_id' => 'client-abc',
        'token_endpoint' => 'https://idp.test/token', 'jwks_uri' => 'https://idp.test/jwks',
    ]);

    $claims = array_merge([
        'iss' => 'https://idp.test', 'aud' => 'client-abc',
        'sub' => 'sub-123', 'email' => 'user@idp.test', 'email_verified' => true,
        'nonce' => 'the-nonce', 'exp' => time() + 300, 'iat' => time(),
    ], $claimOverrides);

    $idToken = JWT::encode($claims, $privatePem, 'RS256', 'test-key');

    return ['provider' => $provider, 'idToken' => $idToken, 'jwks' => $jwks];
}

it('exchanges the code and returns a verified id_token payload', function () {
    ['provider' => $provider, 'idToken' => $idToken, 'jwks' => $jwks] = oidcFixture();

    Http::fake([
        'https://idp.test/token' => Http::response(['id_token' => $idToken, 'access_token' => 'at-1', 'token_type' => 'Bearer']),
        'https://idp.test/jwks' => Http::response($jwks),
    ]);

    $service = app(OidcService::class);
    $tokens = $service->exchangeCode($provider, code: 'the-code', codeVerifier: 'verifier', redirectUri: 'https://app.test/cb');
    $claims = $service->verifyIdToken($provider, $tokens['id_token'], nonce: 'the-nonce');

    expect($claims['sub'])->toBe('sub-123')
        ->and($claims['email'])->toBe('user@idp.test')
        ->and($claims['email_verified'])->toBeTrue();
});

it('rejects an id_token with the wrong nonce', function () {
    ['provider' => $provider, 'idToken' => $idToken, 'jwks' => $jwks] = oidcFixture();
    Http::fake(['https://idp.test/jwks' => Http::response($jwks)]);

    app(OidcService::class)->verifyIdToken($provider, $idToken, nonce: 'different-nonce');
})->throws(RuntimeException::class);

it('rejects an id_token with the wrong audience', function () {
    ['provider' => $provider, 'idToken' => $idToken, 'jwks' => $jwks] = oidcFixture(['aud' => 'someone-else']);
    Http::fake(['https://idp.test/jwks' => Http::response($jwks)]);

    app(OidcService::class)->verifyIdToken($provider, $idToken, nonce: 'the-nonce');
})->throws(RuntimeException::class);
