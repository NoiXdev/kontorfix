<?php

// "Widerrufen" hard-deleted the row, so a revoked key was indistinguishable from one that
// never existed: no record that a credential had been issued and withdrawn, and nothing for
// its owner to see. RegistryToken has carried `revoked_at` since it shipped, for exactly
// this reason; this brings API keys into line with it.
//
// The revoked row must stay unusable — a revocation that only hid the key from a listing
// while it still authenticated would be worse than the hard delete it replaces.
//
// Every request confirms the password inline: these routes sit behind `password.confirm`,
// and without it the request redirects to the confirmation screen and never reaches the
// controller — which reads as a silent success to assertSessionHasNoErrors(), and did while
// this test was being written.

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->confirmed = ['auth.password_confirmed_at' => time()];
});

it('marks the key revoked instead of deleting the row', function () {
    [$key] = ApiKey::issue($this->user, 'deploy', ApiKeyPermission::Read);

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->delete(route('api-keys.destroy', $key))
        ->assertSessionHasNoErrors();

    $stored = ApiKey::find($key->id);

    expect($stored)->not->toBeNull()
        ->and($stored->revoked_at)->not->toBeNull();
});

it('stops authenticating with a revoked key', function () {
    [$key, $plain] = ApiKey::issue($this->user, 'deploy', ApiKeyPermission::Read);

    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->delete(route('api-keys.destroy', $key));

    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
});

it('still shows a revoked key, marked as such', function () {
    // A revoked row that vanished from the listing would look identical to the deletion this
    // replaces — and being able to see that it happened is the point of keeping the row.
    [$key] = ApiKey::issue($this->user, 'deploy', ApiKeyPermission::Read);

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->delete(route('api-keys.destroy', $key));

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->get(route('api-keys.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('apiKeys.0.name', 'deploy')
            ->where('apiKeys.0.revoked', true));
});

it('reports a live key as not revoked', function () {
    ApiKey::issue($this->user, 'deploy', ApiKeyPermission::Read);

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->get(route('api-keys.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('apiKeys.0.revoked', false));
});

it('refuses to revoke somebody elses key', function () {
    $stranger = User::factory()->create();
    [$key] = ApiKey::issue($stranger, 'theirs', ApiKeyPermission::Read);

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->delete(route('api-keys.destroy', $key))
        ->assertForbidden();

    expect(ApiKey::find($key->id)->revoked_at)->toBeNull();
});
