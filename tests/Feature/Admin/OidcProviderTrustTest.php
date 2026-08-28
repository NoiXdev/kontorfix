<?php

// The `trusts_email_claim` column (see OidcTrustsEmailClaimTest / the migration in
// database/migrations) had no way to be turned off on an existing installation: the
// oidc resource route only exposes index/create/store/destroy, and the backfill set
// every pre-existing provider to `true`. This is the single-purpose toggle route that
// closes that gap — PATCH admin/oidc/{provider}/trust — without dragging in a full
// provider-edit form (client-secret rotation, endpoint re-discovery).

use App\Enums\UserRole;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\Oidc\OidcUserResolver;

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->superAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
});

it('lets a super-admin turn the trust flag off on a backfilled-trusted provider, and it persists', function () {
    $provider = OidcProvider::factory()->create(['trusts_email_claim' => true]);

    $this->actingAs($this->superAdmin)
        ->patch(route('admin.oidc.trust', $provider), ['trusts_email_claim' => false])
        ->assertRedirect();

    expect($provider->fresh()->trusts_email_claim)->toBeFalse();
});

it('lets a super-admin turn the trust flag back on', function () {
    $provider = OidcProvider::factory()->create(['trusts_email_claim' => false]);

    $this->actingAs($this->superAdmin)
        ->patch(route('admin.oidc.trust', $provider), ['trusts_email_claim' => true])
        ->assertRedirect();

    expect($provider->fresh()->trusts_email_claim)->toBeTrue();
});

it('changes real resolver behaviour end-to-end: a provider that linked by email before the toggle refuses after it', function () {
    $existing = User::factory()->for($this->org)->create([
        'email' => 'anna@firma.de',
        'role' => UserRole::Member,
    ]);
    $provider = OidcProvider::factory()->create(['trusts_email_claim' => true]);

    $claims = [
        'sub' => 'toggle-subject-1',
        'email' => 'anna@firma.de',
        'email_verified' => true,
    ];

    // Before the toggle: the trusted provider auto-links the existing account by email.
    $resolved = app(OidcUserResolver::class)->resolve($provider, $claims);
    expect($resolved->id)->toBe($existing->id);

    // A second, distinct account/subject scenario to prove the toggle — not the earlier
    // link — is what changes the outcome.
    $second = User::factory()->for($this->org)->create([
        'email' => 'bob@firma.de',
        'role' => UserRole::Member,
    ]);

    $this->actingAs($this->superAdmin)
        ->patch(route('admin.oidc.trust', $provider), ['trusts_email_claim' => false])
        ->assertRedirect();

    expect(fn () => app(OidcUserResolver::class)->resolve($provider->fresh(), [
        'sub' => 'toggle-subject-2',
        'email' => 'bob@firma.de',
        'email_verified' => true,
    ]))->toThrow(RuntimeException::class);

    expect($provider->identities()->where('user_id', $second->id)->exists())->toBeFalse();
});

it('forbids a non-super-admin from reaching the trust route', function () {
    $maintainer = User::factory()->for(Organization::factory())->create(['role' => UserRole::Maintainer]);
    $provider = OidcProvider::factory()->create(['trusts_email_claim' => true]);

    $this->actingAs($maintainer)
        ->patch(route('admin.oidc.trust', $provider), ['trusts_email_claim' => false])
        ->assertForbidden();

    expect($provider->fresh()->trusts_email_claim)->toBeTrue();
});
