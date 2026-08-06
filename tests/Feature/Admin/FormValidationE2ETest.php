<?php

use App\Enums\UserRole;
use App\Models\GitCredential;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * End-to-end validation coverage for the admin forms whose CRUD tests exercised the happy
 * path and authorization but not the field-level validation rules. Each case submits an
 * otherwise-valid payload with exactly one rule violated and asserts the error surfaces on
 * that field — so a loosened or dropped rule fails a test.
 */
function formValidationOpAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

// --- Git credentials ---------------------------------------------------------

it('rejects a git credential without a name', function () {
    $org = Organization::factory()->create();
    $this->actingAs(User::factory()->for($org)->create(['role' => UserRole::Admin]))
        ->post('/admin/git-credentials', ['organization_id' => $org->id, 'provider' => 'github', 'token' => 'ghp_x'])
        ->assertSessionHasErrors('name');
});

it('rejects a git credential without a token', function () {
    $org = Organization::factory()->create();
    $this->actingAs(User::factory()->for($org)->create(['role' => UserRole::Admin]))
        ->post('/admin/git-credentials', ['name' => 'GH', 'organization_id' => $org->id, 'provider' => 'github'])
        ->assertSessionHasErrors('token');
});

it('rejects a git credential with an unknown provider', function () {
    $org = Organization::factory()->create();
    $this->actingAs(User::factory()->for($org)->create(['role' => UserRole::Admin]))
        ->post('/admin/git-credentials', ['name' => 'GH', 'organization_id' => $org->id, 'provider' => 'svn', 'token' => 'x'])
        ->assertSessionHasErrors('provider');
});

it('rejects a git credential update without a name', function () {
    $org = Organization::factory()->create();
    $cred = GitCredential::factory()->for($org)->create();
    $this->actingAs(User::factory()->for($org)->create(['role' => UserRole::Admin]))
        ->put("/admin/git-credentials/{$cred->id}", ['provider' => 'github'])
        ->assertSessionHasErrors('name');
});

// --- Incoming webhooks -------------------------------------------------------

it('rejects an incoming webhook without a name', function () {
    $this->actingAs(formValidationOpAdmin())
        ->post('/admin/incoming-webhooks', ['provider' => 'github'])
        ->assertSessionHasErrors('name');
});

it('rejects an incoming webhook with an unsupported provider', function () {
    $this->actingAs(formValidationOpAdmin())
        ->post('/admin/incoming-webhooks', ['name' => 'CI', 'provider' => 'perforce'])
        ->assertSessionHasErrors('provider');
});

// --- OIDC provider -----------------------------------------------------------

it('rejects an oidc provider missing required endpoints', function () {
    $this->actingAs(formValidationOpAdmin())
        ->post('/admin/oidc', ['name' => 'Keycloak', 'slug' => 'kc', 'client_id' => 'id', 'client_secret' => 'sec', 'issuer' => 'https://idp.test'])
        ->assertSessionHasErrors(['authorization_endpoint', 'token_endpoint', 'jwks_uri']);
});

it('rejects an oidc provider with a malformed slug', function () {
    $this->actingAs(formValidationOpAdmin())
        ->post('/admin/oidc', [
            'name' => 'Keycloak', 'slug' => 'Not Valid', 'client_id' => 'id', 'client_secret' => 'sec',
            'issuer' => 'https://idp.test', 'authorization_endpoint' => 'https://idp.test/auth',
            'token_endpoint' => 'https://idp.test/token', 'jwks_uri' => 'https://idp.test/jwks',
        ])
        ->assertSessionHasErrors('slug');
});

it('rejects an oidc provider with a duplicate slug', function () {
    OidcProvider::factory()->create(['slug' => 'kc']);
    $this->actingAs(formValidationOpAdmin())
        ->post('/admin/oidc', [
            'name' => 'Keycloak', 'slug' => 'kc', 'client_id' => 'id', 'client_secret' => 'sec',
            'issuer' => 'https://idp.test', 'authorization_endpoint' => 'https://idp.test/auth',
            'token_endpoint' => 'https://idp.test/token', 'jwks_uri' => 'https://idp.test/jwks',
        ])
        ->assertSessionHasErrors('slug');
});

// --- Robots ------------------------------------------------------------------

it('rejects a robot without a name', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $this->actingAs(User::factory()->for($op)->create(['role' => UserRole::Admin]))
        ->post('/admin/robots', ['organization_id' => $op->id, 'role' => 'maintainer'])
        ->assertSessionHasErrors('name');
});

it('rejects a robot with a non-existent organization', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $this->actingAs(User::factory()->for($op)->create(['role' => UserRole::Admin]))
        ->post('/admin/robots', ['name' => 'CI', 'organization_id' => (string) Str::uuid(), 'role' => 'maintainer'])
        ->assertSessionHasErrors('organization_id');
});

it('rejects a robot with an invalid role', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $this->actingAs(User::factory()->for($op)->create(['role' => UserRole::Admin]))
        ->post('/admin/robots', ['name' => 'CI', 'organization_id' => $op->id, 'role' => 'emperor'])
        ->assertSessionHasErrors('role');
});

// --- System settings ---------------------------------------------------------

it('rejects a system update without the registration flag', function () {
    $this->actingAs(formValidationOpAdmin())
        ->put('/admin/system', [])
        ->assertSessionHasErrors('registration_enabled');
});

it('rejects a system update with an unknown registry type', function () {
    $this->actingAs(formValidationOpAdmin())
        ->put('/admin/system', ['registration_enabled' => true, 'enabled_registry_types' => ['composer', 'cargo']])
        ->assertSessionHasErrors('enabled_registry_types.1');
});
