<?php

use App\Enums\UserRole;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
});

it('lists providers without exposing the secret', function () {
    OidcProvider::factory()->create(['name' => 'Authentik', 'client_secret' => 'shh']);
    $this->actingAs($this->admin)->get('/admin/oidc')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/oidc/Index')->has('providers', 1)
            ->where('providers.0.has_secret', true)->missing('providers.0.client_secret'));
});

it('forbids maintainers from managing providers', function () {
    $maint = User::factory()->for(Organization::factory())->create(['role' => UserRole::Maintainer]);
    $this->actingAs($maint)->get('/admin/oidc')->assertForbidden();
});

it('creates a provider', function () {
    $this->actingAs($this->admin)->post('/admin/oidc', [
        'name' => 'Keycloak', 'slug' => 'keycloak', 'client_id' => 'cid', 'client_secret' => 'sec',
        'issuer' => 'https://idp.test', 'authorization_endpoint' => 'https://idp.test/a',
        'token_endpoint' => 'https://idp.test/t', 'jwks_uri' => 'https://idp.test/j',
        'scopes' => 'openid email profile',
    ])->assertRedirect();
    expect(OidcProvider::where('slug', 'keycloak')->exists())->toBeTrue();
});

it('fills endpoints from discovery', function () {
    Http::fake(['https://idp.test/.well-known/openid-configuration' => Http::response([
        'issuer' => 'https://idp.test', 'authorization_endpoint' => 'https://idp.test/authorize',
        'token_endpoint' => 'https://idp.test/token', 'userinfo_endpoint' => 'https://idp.test/userinfo', 'jwks_uri' => 'https://idp.test/jwks',
    ])]);

    $this->actingAs($this->admin)->postJson('/admin/oidc/discover', ['issuer' => 'https://idp.test'])
        ->assertOk()->assertJsonPath('authorization_endpoint', 'https://idp.test/authorize');
});
