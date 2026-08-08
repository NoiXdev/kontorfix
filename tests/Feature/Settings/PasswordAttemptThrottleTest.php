<?php

// The metered comparison on `POST /confirm-password` is worth its least-guarded sibling.
// Three other routes resolve the same hash — `DELETE /settings/two-factor`,
// `PUT /settings/password`, `DELETE /settings/profile` — and an attacker holding a stolen
// session moves to whichever one is not counting. These tests pin that all four share one
// pair of buckets, and that neither bucket can be turned into a lockout button.

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

function userWithConfirmedTwoFactor(): User
{
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => app(TwoFactorAuthenticator::class)->generateSecret(),
        'two_factor_recovery_codes' => ['keepme0-keepme0'],
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

/** The confirm-password bucket must already be spent when the sibling is probed. */
function burnConfirmPasswordBucket(User $user): void
{
    foreach (range(1, 6) as $i) {
        test()->actingAs($user)->post('/confirm-password', ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
    }
}

it('refuses a two-factor disable once the confirm-password bucket is spent', function () {
    $user = userWithConfirmedTwoFactor();
    burnConfirmPasswordBucket($user);

    // Same principal, same source address, different route: the counter has to follow the
    // hash, not the endpoint. The correct password is used deliberately — if the sibling
    // were still on the framework's `current_password` this would succeed and strip TOTP.
    $this->actingAs($user)->delete('/settings/two-factor', ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('refuses a password change once the confirm-password bucket is spent', function () {
    $user = User::factory()->create();
    burnConfirmPasswordBucket($user);

    $this->actingAs($user)->put('/settings/password', [
        'current_password' => 'password',
        'password' => 'new-password-1234',
        'password_confirmation' => 'new-password-1234',
    ])->assertSessionHasErrors('current_password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('refuses an account deletion once the confirm-password bucket is spent', function () {
    $user = User::factory()->create();
    burnConfirmPasswordBucket($user);

    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect(User::find($user->id))->not->toBeNull();
});

it('fills the shared bucket from a sibling route, not only from confirm-password', function () {
    $user = userWithConfirmedTwoFactor();

    // Guess against the sibling. Each miss must cost the attacker the same budget that
    // guessing on `POST /confirm-password` would have cost.
    foreach (range(1, 6) as $i) {
        $this->actingAs($user)->delete('/settings/two-factor', ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
    }

    $this->actingAs($user)->post('/confirm-password', ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('leaves a trace for a failed comparison on a sibling route', function () {
    Event::fake([Failed::class]);
    $user = userWithConfirmedTwoFactor();

    $this->actingAs($user)->delete('/settings/two-factor', ['password' => 'wrong'])
        ->assertSessionHasErrors('password');

    Event::assertDispatched(Failed::class, fn (Failed $e) => $e->user?->is($user) === true);
});

it('never lets the account-wide counter refuse the owner on a sibling route', function () {
    $user = userWithConfirmedTwoFactor();

    // Burn the IP-free account counter the way an attacker on a pool of addresses would.
    // It gates failures only: if it could refuse before the comparison, anyone holding a
    // session could lock the owner out of disabling their own second factor.
    foreach (range(1, 40) as $i) {
        RateLimiter::hit('confirm-password-account|'.$user->id, 900);
    }

    $this->actingAs($user)->delete('/settings/two-factor', ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('clears both buckets when the owner proves the password on a sibling route', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $i) {
        $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
    }

    $this->actingAs($user)->put('/settings/password', [
        'current_password' => 'password',
        'password' => 'new-password-1234',
        'password_confirmation' => 'new-password-1234',
    ])->assertSessionHasNoErrors();

    expect(RateLimiter::attempts('confirm-password-account|'.$user->id))->toBe(0);
});
