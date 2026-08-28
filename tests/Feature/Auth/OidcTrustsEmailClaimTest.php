<?php

// `OidcUserResolver` used to trust every configured provider's `email_verified` claim
// equally: any IdP asserting a matching, verified email got auto-linked to the existing
// (unprivileged) account, including a second, less trustworthy provider added later. The
// `trusts_email_claim` column on `oidc_providers` gates that specifically — independent of
// `allow_registration`, which governs whether the provider may bring in new people at all.
//
// An untrusted provider whose asserted email matches an existing account must not fall
// through into `User::create` (that would hit the email unique index and surface an
// unrendered SQLSTATE) — it must refuse explicitly, with a message telling the operator
// what to do.

use App\Enums\UserRole;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\Oidc\OidcUserResolver;

beforeEach(function () {
    $this->org = Organization::factory()->create();
});

it('refuses an untrusted provider claiming an existing unprivileged account by email, without an SQL error', function () {
    $existing = User::factory()->for($this->org)->create([
        'email' => 'anna@firma.de',
        'role' => UserRole::Member,
    ]);
    $provider = OidcProvider::factory()->create([
        'trusts_email_claim' => false,
        'allow_registration' => true,
        'default_organization_id' => $this->org->id,
    ]);

    expect(fn () => app(OidcUserResolver::class)->resolve($provider, [
        'sub' => 'untrusted-subject-1',
        'email' => 'anna@firma.de',
        'email_verified' => true,
    ]))->toThrow(RuntimeException::class, 'Für diese E-Mail-Adresse existiert bereits ein Konto. Dieser Provider ist nicht als vertrauenswürdig für E-Mail-Zusicherungen markiert und darf ihn deshalb nicht automatisch verknüpfen. Verknüpfen Sie das Konto gezielt im angemeldeten Zustand, oder markieren Sie den Provider als vertrauenswürdig für E-Mail-Zusicherungen.');

    expect(User::count())->toBe(1)
        ->and($existing->fresh()->email)->toBe('anna@firma.de')
        ->and($provider->identities()->count())->toBe(0);
});

it('still links the existing member account when the provider is trusted', function () {
    $existing = User::factory()->for($this->org)->create([
        'email' => 'anna@firma.de',
        'role' => UserRole::Member,
    ]);
    $provider = OidcProvider::factory()->create(['trusts_email_claim' => true]);

    $resolved = app(OidcUserResolver::class)->resolve($provider, [
        'sub' => 'trusted-subject-1',
        'email' => 'anna@firma.de',
        'email_verified' => true,
    ]);

    expect($resolved->id)->toBe($existing->id)
        ->and(User::count())->toBe(1)
        ->and($provider->identities()->where('subject', 'trusted-subject-1')->where('user_id', $existing->id)->exists())->toBeTrue();
});

it('still refuses the privileged-account guard for a trusted provider', function () {
    $admin = User::factory()->for($this->org)->create([
        'email' => 'boss@firma.de',
        'role' => UserRole::Admin,
    ]);
    $provider = OidcProvider::factory()->create(['trusts_email_claim' => true]);

    expect(fn () => app(OidcUserResolver::class)->resolve($provider, [
        'sub' => 'trusted-subject-2',
        'email' => 'boss@firma.de',
        'email_verified' => true,
    ]))->toThrow(RuntimeException::class, 'Automatische SSO-Verknüpfung für privilegierte Konten ist nicht erlaubt.');

    expect(User::count())->toBe(1)
        ->and($provider->identities()->count())->toBe(0);
});

it('logs in via an already-linked subject regardless of the trust marker', function () {
    $user = User::factory()->for($this->org)->create(['email' => 'someone@firma.de']);
    $provider = OidcProvider::factory()->create(['trusts_email_claim' => false]);
    $provider->identities()->create(['user_id' => $user->id, 'subject' => 'linked-subject-1']);

    // A claims payload that would have been refused had it gone through email matching —
    // it never does, because the subject is already linked.
    $resolved = app(OidcUserResolver::class)->resolve($provider, [
        'sub' => 'linked-subject-1',
        'email' => 'someone-else@firma.de',
        'email_verified' => true,
    ]);

    expect($resolved->id)->toBe($user->id)
        ->and(User::count())->toBe(1);
});
