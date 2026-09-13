<?php

// `OidcUserResolver` matched an asserted email across the WHOLE instance: the lookup was
// `User::whereRaw('lower(email) = ?')` with nothing tying the row it found to the
// organization the provider belongs to. A tenant's own IdP could therefore assert any
// address it liked, and if that address happened to belong to a member of a DIFFERENT
// tenant, the provider was linked to that account and logged in as them — no password, and
// the victim's local TOTP never consulted, because the OIDC path does not pass through the
// two-factor challenge.
//
// `trusts_email_claim` did not stop this: the column asks "may this provider claim accounts
// by email at all", never "whose accounts", and the migration that introduced it backfilled
// every provider that already existed to `true`.

use App\Enums\UserRole;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\Oidc\OidcUserResolver;

beforeEach(function () {
    $this->tenant = Organization::factory()->create();
    $this->otherTenant = Organization::factory()->create();
});

it('refuses to link an account that belongs to a different organization', function () {
    $victim = User::factory()->for($this->otherTenant)->create([
        'email' => 'anna@opfer.de',
        'role' => UserRole::Member,
    ]);

    $provider = OidcProvider::factory()->create([
        'trusts_email_claim' => true,
        'allow_registration' => false,
        'default_organization_id' => $this->tenant->id,
    ]);

    expect(fn () => app(OidcUserResolver::class)->resolve($provider, [
        'sub' => 'attacker-subject',
        'email' => 'anna@opfer.de',
        'email_verified' => true,
    ]))->toThrow(RuntimeException::class);

    expect($victim->fresh()->oidcIdentities()->count())->toBe(0)
        ->and($provider->identities()->count())->toBe(0);
});

it('refuses the cross-tenant claim even when the provider may register, instead of silently creating a lookalike', function () {
    User::factory()->for($this->otherTenant)->create([
        'email' => 'anna@opfer.de',
        'role' => UserRole::Member,
    ]);

    $provider = OidcProvider::factory()->create([
        'trusts_email_claim' => true,
        'allow_registration' => true,
        'default_organization_id' => $this->tenant->id,
    ]);

    expect(fn () => app(OidcUserResolver::class)->resolve($provider, [
        'sub' => 'attacker-subject',
        'email' => 'anna@opfer.de',
        'email_verified' => true,
    ]))->toThrow(RuntimeException::class);

    // The registration branch must not be the consolation prize: creating a second account
    // on the same address would hit the unique index and surface a raw SQLSTATE.
    expect(User::where('email', 'anna@opfer.de')->count())->toBe(1);
});

it('still links an account of the provider own organization', function () {
    $member = User::factory()->for($this->tenant)->create([
        'email' => 'anna@firma.de',
        'role' => UserRole::Member,
    ]);

    $provider = OidcProvider::factory()->create([
        'trusts_email_claim' => true,
        'default_organization_id' => $this->tenant->id,
    ]);

    $resolved = app(OidcUserResolver::class)->resolve($provider, [
        'sub' => 'own-subject',
        'email' => 'anna@firma.de',
        'email_verified' => true,
    ]);

    expect($resolved->id)->toBe($member->id)
        ->and($provider->identities()->count())->toBe(1);
});

it('links an account whose membership in the provider organization is an additional one, not its home org', function () {
    // accessibleOrganizationIds() is the project's own answer to "which organizations is
    // this person part of", and it spans the home org plus every pivot membership. A
    // consultant whose home org is elsewhere but who is a member here is legitimately this
    // provider's user.
    $member = User::factory()->for($this->otherTenant)->create([
        'email' => 'berater@extern.de',
        'role' => UserRole::Member,
    ]);
    $member->organizations()->attach($this->tenant->id, ['role' => UserRole::Member->value]);

    $provider = OidcProvider::factory()->create([
        'trusts_email_claim' => true,
        'default_organization_id' => $this->tenant->id,
    ]);

    $resolved = app(OidcUserResolver::class)->resolve($provider, [
        'sub' => 'consultant-subject',
        'email' => 'berater@extern.de',
        'email_verified' => true,
    ]);

    expect($resolved->id)->toBe($member->id);
});

it('refuses email linking outright for a provider with no organization to scope it to', function () {
    User::factory()->for($this->tenant)->create([
        'email' => 'anna@firma.de',
        'role' => UserRole::Member,
    ]);

    $provider = OidcProvider::factory()->create([
        'trusts_email_claim' => true,
        'allow_registration' => false,
        'default_organization_id' => null,
    ]);

    // Without an organization there is no boundary to check the match against, and
    // "unscoped" must mean "refuse", never "match anyone".
    expect(fn () => app(OidcUserResolver::class)->resolve($provider, [
        'sub' => 'unscoped-subject',
        'email' => 'anna@firma.de',
        'email_verified' => true,
    ]))->toThrow(RuntimeException::class);

    expect($provider->identities()->count())->toBe(0);
});

it('leaves an existing identity link working regardless of organization', function () {
    // Scoping governs how a link is CREATED. An identity an operator established
    // deliberately stays valid — otherwise this change would lock out consultants whose
    // membership arrangement changed after they linked.
    $user = User::factory()->for($this->otherTenant)->create(['role' => UserRole::Member]);
    $provider = OidcProvider::factory()->create([
        'trusts_email_claim' => false,
        'default_organization_id' => $this->tenant->id,
    ]);
    $provider->identities()->create(['user_id' => $user->id, 'subject' => 'linked-subject']);

    $resolved = app(OidcUserResolver::class)->resolve($provider, [
        'sub' => 'linked-subject',
        'email' => 'irrelevant@example.test',
        'email_verified' => true,
    ]);

    expect($resolved->id)->toBe($user->id);
});
