<?php

// This application creates accounts nobody holds a password for: OIDC-provisioned users,
// admin-invited users and passkey-only users all carry a random hash. Every gated surface
// offers them a way through — the confirmation screen accepts a passkey assertion or mails
// a set-password link — except the two routes that proved the password inline. So such a
// user could *enable* a second factor and never switch it off, and could never delete their
// own account. These tests pin the way out, and pin that it did not become a way past.

use App\Http\Middleware\ConfirmPasswordUnlessSubmitted;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Support\Str;

function passwordlessUser(): User
{
    // Exactly what the invitation and OIDC paths produce: a hash derived from a random
    // string nobody ever sees. Indistinguishable server-side from a password the owner
    // knows, which is why the escape hatch cannot be conditional on account shape.
    return User::factory()->create(['password' => bcrypt(Str::random(40))]);
}

function withConfirmedTwoFactor(User $user): User
{
    $user->forceFill([
        'two_factor_secret' => app(TwoFactorAuthenticator::class)->generateSecret(),
        'two_factor_recovery_codes' => ['keepme0-keepme0'],
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

it('sends a two-factor disable with no password to the confirmation screen', function () {
    $user = withConfirmedTwoFactor(passwordlessUser());

    $this->actingAs($user)->delete('/settings/two-factor')
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('disables two-factor for an account with no usable password once the gate is satisfied', function () {
    $user = withConfirmedTwoFactor(passwordlessUser());

    // What a passkey assertion at `passkey.confirm` leaves behind. Before this the route
    // was unreachable for such an account in any way at all.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/settings/two-factor')
        ->assertSessionHasNoErrors();

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('deletes a passwordless account once the gate is satisfied, and not before', function () {
    $user = passwordlessUser();

    $this->actingAs($user)->delete('/settings/profile')
        ->assertRedirect(route('password.confirm'));

    expect(User::find($user->id))->not->toBeNull();

    // Anchor: the same request, differing only in the session stamp, goes through — so the
    // redirect above is ConfirmPasswordUnlessSubmitted rather than `auth` or the route.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/settings/profile')
        ->assertRedirect('/');

    expect(User::find($user->id))->toBeNull();
});

it('still refuses a wrong password even with a fresh confirmation in session', function () {
    $user = withConfirmedTwoFactor(User::factory()->create());

    // The hatch is "submit nothing and prove yourself elsewhere", not "the field stopped
    // being checked". A submitted password is still compared, and a wrong one still loses.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/settings/two-factor', ['password' => 'not-the-password'])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('does not accept a stale confirmation for either route', function () {
    $user = withConfirmedTwoFactor(passwordlessUser());

    // These two routes used to demand the password on the acting request itself. Letting
    // them inherit the shared fifteen-minute window would be a real weakening, so they ask
    // for five minutes — the same freshness the address change asks for.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time() - 600])
        ->delete('/settings/two-factor')
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time() - 600])
        ->delete('/settings/profile')
        ->assertRedirect(route('password.confirm'));

    expect(User::find($user->id))->not->toBeNull();
});

it('never deletes an account on no proof at all', function () {
    $user = passwordlessUser();

    // The form request is fail-closed independently of the middleware: requiredIf is an
    // implicit rule, so it runs even when `nullable` has skipped everything else. A route
    // that lost the middleware falls back to demanding the password, never to accepting
    // an empty field.
    $this->withoutMiddleware(ConfirmPasswordUnlessSubmitted::class);

    $this->actingAs($user)->delete('/settings/profile')
        ->assertRedirect()
        ->assertSessionHasErrors('password');

    expect(User::find($user->id))->not->toBeNull();
});

it('never strips a second factor on no proof at all', function () {
    // Deliberately its own test rather than a second leg of the one above: AuthenticateSession
    // pins a session to the hash it was created under, so switching principals mid-test
    // bounces the second request to /login and the assertion would pass on that redirect
    // instead of on the rule it names.
    $user = withConfirmedTwoFactor(passwordlessUser());

    $this->withoutMiddleware(ConfirmPasswordUnlessSubmitted::class);

    $this->actingAs($user)->delete('/settings/two-factor')
        ->assertRedirect()
        ->assertSessionHasErrors('password');

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});
