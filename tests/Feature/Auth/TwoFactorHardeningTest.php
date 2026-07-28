<?php

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;

function confirmedTwoFactorUser(): User
{
    $user = User::factory()->create();
    $tfa = app(TwoFactorAuthenticator::class);
    $user->forceFill([
        'two_factor_secret' => $tfa->generateSecret(),
        'two_factor_recovery_codes' => ['keepme0-keepme0'],
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

it('rejects a replayed totp code on a second login', function () {
    $user = confirmedTwoFactorUser();
    $code = app(TwoFactorAuthenticator::class)->currentCode($user->two_factor_secret);

    // First login with the code: succeeds.
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['code' => $code])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);

    // Log out and replay the same code: must be rejected (replay protection).
    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->from(route('two-factor.login'))
        ->post('/two-factor-challenge', ['code' => $code])
        ->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('locks the challenge after five failed attempts, even for a valid code', function () {
    $user = confirmedTwoFactorUser();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    foreach (range(1, 5) as $ignored) {
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
    }

    // Sixth attempt is throttled — even a valid code no longer gets through.
    $code = app(TwoFactorAuthenticator::class)->currentCode($user->two_factor_secret);
    $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('refuses to re-enable two factor while it is already confirmed', function () {
    $user = confirmedTwoFactorUser();
    $secretBefore = $user->two_factor_secret;

    $this->actingAs($user)->post('/settings/two-factor/enable')->assertSessionHasErrors('two_factor');

    // Secret unchanged, still confirmed — no falling back to the unconfirmed state.
    $fresh = $user->fresh();
    expect($fresh->hasConfirmedTwoFactor())->toBeTrue();
    expect($fresh->two_factor_secret)->toBe($secretBefore);
});
