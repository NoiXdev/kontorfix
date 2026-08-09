<?php

// `users.email` is a plain case-sensitive btree — no citext, no lowercasing mutator. An
// IdP asserting a differently-cased form of an existing address therefore missed the row,
// skipped the guard that refuses to auto-link a privileged account, and created a second
// lookalike account instead of being refused.

use App\Enums\UserRole;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\Oidc\OidcUserResolver;

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->provider = OidcProvider::factory()->create([
        'allow_registration' => true,
        'default_organization_id' => $this->org->id,
        'default_role' => UserRole::Member,
    ]);
});

it('refuses to auto-link a privileged account asserted in a different case', function () {
    User::factory()->for($this->org)->create([
        'email' => 'root@firma.de',
        'role' => UserRole::Admin,
    ]);

    expect(fn () => app(OidcUserResolver::class)->resolve($this->provider, [
        'sub' => 'idp-subject-1',
        'email' => 'Root@Firma.de',
        'email_verified' => true,
    ]))->toThrow(RuntimeException::class);

    expect(User::count())->toBe(1);
});

it('links the existing member account instead of creating a lookalike', function () {
    $existing = User::factory()->for($this->org)->create([
        'email' => 'anna@firma.de',
        'role' => UserRole::Member,
    ]);

    $resolved = app(OidcUserResolver::class)->resolve($this->provider, [
        'sub' => 'idp-subject-2',
        'email' => 'ANNA@FIRMA.DE',
        'email_verified' => true,
    ]);

    expect($resolved->id)->toBe($existing->id)
        ->and(User::count())->toBe(1);
});

it('still registers a genuinely new address', function () {
    $resolved = app(OidcUserResolver::class)->resolve($this->provider, [
        'sub' => 'idp-subject-3',
        'email' => 'Neu@Firma.de',
        'email_verified' => true,
    ]);

    // Stored normalised, so the login form and a later assertion in any casing both find it.
    expect($resolved->email)->toBe('neu@firma.de');
});
