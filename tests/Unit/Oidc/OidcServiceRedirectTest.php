<?php

use App\Models\OidcProvider;
use App\Services\Auth\Oidc\OidcService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds an authorization url with pkce, state and nonce', function () {
    $provider = OidcProvider::factory()->create([
        'authorization_endpoint' => 'https://idp.test/authorize',
        'client_id' => 'client-abc',
        'scopes' => 'openid email profile',
    ]);

    $url = app(OidcService::class)->authorizationUrl(
        $provider,
        redirectUri: 'https://app.test/auth/oidc/authentik/callback',
        state: 'the-state',
        nonce: 'the-nonce',
        codeChallenge: 'the-challenge',
    );

    expect($url)->toStartWith('https://idp.test/authorize?');
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    expect($q['response_type'])->toBe('code')
        ->and($q['client_id'])->toBe('client-abc')
        ->and($q['redirect_uri'])->toBe('https://app.test/auth/oidc/authentik/callback')
        ->and($q['scope'])->toBe('openid email profile')
        ->and($q['state'])->toBe('the-state')
        ->and($q['nonce'])->toBe('the-nonce')
        ->and($q['code_challenge'])->toBe('the-challenge')
        ->and($q['code_challenge_method'])->toBe('S256');
});
