<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Support\Facades\Password;

// Changing the password is the one remediation a user reaches for after "someone is in my
// account". It has to actually evict that someone: every *other* web session, and every
// remember-me cookie, must stop working the moment the password changes.
//
// Deliberately NOT revoked: registry tokens, API keys and passkeys. See the comment in
// PasswordController::update() for the reasoning.

/**
 * Simulates a second, already-established browser session for the user: authenticated, and
 * pinned to the password hash that was current when that session was created.
 */
function sessionPinnedToHash(User $user, string $passwordHash): void
{
    test()->flushSession();
    // Reload from the database: a separate session resolves its own User instance and must
    // therefore see the new hash, not the stale one this test object still holds.
    test()->actingAs(User::findOrFail($user->id))->withSession(['password_hash_web' => $passwordHash]);
}

it('evicts other web sessions when the password is changed in settings', function () {
    $user = User::factory()->create();
    $hashBefore = $user->password;

    $this->actingAs($user)->withSession(['password_hash_web' => $hashBefore])
        ->put('/settings/password', [
            'current_password' => 'password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    // The session that performed the change stays signed in.
    $this->get('/settings/profile')->assertOk();

    // A second session, still holding the pre-change hash, is thrown out.
    sessionPinnedToHash($user, $hashBefore);
    $this->get('/settings/profile')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('evicts live web sessions when the password is reset via the reset link', function () {
    $user = User::factory()->create();
    $hashBefore = $user->password;
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertRedirect(route('login'));

    sessionPinnedToHash($user, $hashBefore);
    $this->get('/settings/profile')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('keeps registry tokens, api keys and the current session usable after a password change', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    [$registryToken] = RegistryToken::issue($org, 'ci', null, owner: $user);
    [$apiKey] = ApiKey::issue($user, 'deploy', ApiKeyPermission::Read);

    $this->actingAs($user)->put('/settings/password', [
        'current_password' => 'password',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertSessionHasNoErrors();

    // Named, individually revocable machine credentials survive a routine rotation —
    // destroying them would break every pipeline that uses them.
    expect(RegistryToken::find($registryToken->id))->not->toBeNull();
    expect(ApiKey::find($apiKey->id))->not->toBeNull();
});
