<?php

// `users.email` is the account's password-reset channel, so whoever moves it owns the
// account from then on — and keeps it after their own access is revoked, because what they
// end up holding is the password. The web directory re-proves the password before letting
// that happen. `PUT /api/v1/users/{user}` cannot: AuthenticateApiKey admits any non-GET on
// a `write` key and calls Auth::setUser(), while RequirePassword reads a session key that
// does not exist there. A leaked super-admin key therefore converted into permanent
// ownership of any account on the instance. There is no honest gate for a stateless
// surface, so the field is refused on it.

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\User;

function superAdminWithWriteKey(): array
{
    $admin = User::factory()->create(['role' => UserRole::Admin, 'is_super_admin' => true]);

    [, $plain] = ApiKey::issue($admin, 'ci', ApiKeyPermission::Write);

    return [$admin, $plain];
}

it('refuses to move an account address through an API key', function () {
    [$admin, $plain] = superAdminWithWriteKey();
    $victim = User::factory()->create(['email' => 'victim@example.com']);

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->putJson("/api/v1/users/{$victim->id}", [
            'role' => $victim->role->value,
            'email' => 'attacker@example.net',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect($victim->fresh()->email)->toBe('victim@example.com');
});

it('still lets an API key change everything about a user that revocation undoes', function () {
    [$admin, $plain] = superAdminWithWriteKey();
    $victim = User::factory()->create(['email' => 'victim@example.com', 'role' => UserRole::Member]);

    // Reachability anchor: the very same caller, route, key and permission — only the
    // `email` field is gone. A 200 here proves the 422 above is this rule's doing and not
    // the `super` gate, the key's permission check, the per-key throttle or route binding.
    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->putJson("/api/v1/users/{$victim->id}", [
            'role' => UserRole::Admin->value,
            'name' => 'Renamed By CI',
        ])
        ->assertOk();

    expect($victim->fresh()->role)->toBe(UserRole::Admin)
        ->and($victim->fresh()->name)->toBe('Renamed By CI');
});

it('accepts a full-object PUT that resubmits the unchanged address', function () {
    [$admin, $plain] = superAdminWithWriteKey();
    $victim = User::factory()->create(['email' => 'victim@example.com']);

    // An idempotent client sends the whole resource back. Refusing the *field* rather than
    // the *change* would break every such caller for no security gain.
    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->putJson("/api/v1/users/{$victim->id}", [
            'role' => $victim->role->value,
            'email' => 'VICTIM@example.com',
            'name' => $victim->name,
        ])
        ->assertOk();
});

it('refuses an address payload the comparison cannot read', function () {
    [$admin, $plain] = superAdminWithWriteKey();
    $victim = User::factory()->create(['email' => 'victim@example.com']);

    // "Unreadable" must resolve to "changed" on a gate's side of the decision, never to
    // "unchanged" — the same rule ConfirmPasswordOnEmailChange applies.
    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->putJson("/api/v1/users/{$victim->id}", [
            'role' => $victim->role->value,
            'email' => ['victim@example.com'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect($victim->fresh()->email)->toBe('victim@example.com');
});

it('gates the web directory behind a fresh password confirmation instead', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
    $victim = User::factory()->create(['email' => 'victim@example.com']);

    // Same field, same trust level, a surface that *can* re-prove the password: gate rather
    // than refuse. Without a confirmation in session the request never reaches the
    // controller.
    $this->actingAs($admin)
        ->put("/admin/users/{$victim->id}", [
            'role' => $victim->role->value,
            'email' => 'attacker@example.net',
        ])
        ->assertRedirect(route('password.confirm'));

    expect($victim->fresh()->email)->toBe('victim@example.com');

    // Anchor: the identical request with a confirmation in session goes through, so the
    // redirect above is ConfirmPasswordOnEmailChange and not the `super` middleware,
    // validation or the route.
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put("/admin/users/{$victim->id}", [
            'role' => $victim->role->value,
            'email' => 'moved@example.net',
        ])
        ->assertSessionHasNoErrors();

    expect($victim->fresh()->email)->toBe('moved@example.net');
});

it('measures the change against the account being edited, not against the editor', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
    $victim = User::factory()->create(['email' => 'victim@example.com', 'name' => 'Vic']);

    // The edit form posts the whole record back, including the address it is not touching.
    // Comparing that against the *caller's* address — which is what a gate written for
    // `PATCH /settings/profile` does unchanged — turns every rename into a password
    // prompt, and would mean the gate is not actually watching the field it claims to.
    $this->actingAs($admin)
        ->put("/admin/users/{$victim->id}", [
            'role' => $victim->role->value,
            'email' => 'victim@example.com',
            'name' => 'Victoria',
        ])
        ->assertSessionHasNoErrors();

    expect($victim->fresh()->name)->toBe('Victoria');
});

it('leaves a role-only edit in the web directory ungated', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
    $victim = User::factory()->create(['role' => UserRole::Member]);

    // The gate is on the takeover primitive, not on the `super` group: a dropdown change
    // must not demand a password.
    $this->actingAs($admin)
        ->put("/admin/users/{$victim->id}", ['role' => UserRole::Admin->value])
        ->assertSessionHasNoErrors();

    expect($victim->fresh()->role)->toBe(UserRole::Admin);
});
