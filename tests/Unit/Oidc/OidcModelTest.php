<?php

use App\Models\OidcIdentity;
use App\Models\OidcProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores an oidc provider with an encrypted secret and links identities', function () {
    $provider = OidcProvider::factory()->create([
        'slug' => 'authentik',
        'client_secret' => 'supersecret',
        'scopes' => 'openid email profile',
        'enabled' => true,
    ]);

    $raw = DB::table('oidc_providers')->where('id', $provider->id)->value('client_secret');
    expect($raw)->not->toBe('supersecret');
    expect($provider->client_secret)->toBe('supersecret');

    $user = User::factory()->create();
    $identity = OidcIdentity::create([
        'oidc_provider_id' => $provider->id,
        'user_id' => $user->id,
        'subject' => 'sub-123',
    ]);

    expect($identity->user->is($user))->toBeTrue();
    expect($user->oidcIdentities()->count())->toBe(1);
});
