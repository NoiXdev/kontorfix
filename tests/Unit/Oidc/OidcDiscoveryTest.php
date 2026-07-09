<?php

use App\Services\Auth\Oidc\OidcDiscovery;
use Illuminate\Support\Facades\Http;

it('resolves endpoints from the discovery document', function () {
    Http::fake([
        'https://idp.test/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://idp.test',
            'authorization_endpoint' => 'https://idp.test/authorize',
            'token_endpoint' => 'https://idp.test/token',
            'userinfo_endpoint' => 'https://idp.test/userinfo',
            'jwks_uri' => 'https://idp.test/jwks',
        ]),
    ]);

    $config = app(OidcDiscovery::class)->discover('https://idp.test');

    expect($config['authorization_endpoint'])->toBe('https://idp.test/authorize')
        ->and($config['token_endpoint'])->toBe('https://idp.test/token')
        ->and($config['jwks_uri'])->toBe('https://idp.test/jwks');
});

it('rejects an unsafe issuer url', function () {
    app(OidcDiscovery::class)->discover('http://127.0.0.1/x');
})->throws(RuntimeException::class);

it('rejects a discovery document pointing endpoints at internal hosts', function () {
    Http::fake([
        'https://evil.test/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://evil.test',
            'authorization_endpoint' => 'http://169.254.169.254/latest',
            'token_endpoint' => 'https://evil.test/token',
            'jwks_uri' => 'https://evil.test/jwks',
        ]),
    ]);

    app(OidcDiscovery::class)->discover('https://evil.test');
})->throws(RuntimeException::class);
