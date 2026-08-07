<?php

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;

it('runs the full enroll -> logout -> challenge -> login lifecycle', function () {
    $tfa = app(TwoFactorAuthenticator::class);
    $user = User::factory()->create();

    // Set up + confirm. Enrollment sits behind `password.confirm` (see
    // TwoFactorPasswordConfirmationTest), so the session carries a fresh confirmation.
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/settings/two-factor/enable');
    $secret = $user->fresh()->two_factor_secret;
    $this->actingAs($user)->post('/settings/two-factor/confirm', ['code' => $tfa->currentCode($secret)])
        ->assertSessionHasNoErrors();
    expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue();

    // Log out
    $this->post('/logout');
    $this->assertGuest();

    // Login with password ⇒ challenge, not yet logged in
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    // Challenge with valid TOTP ⇒ logged in
    $this->post('/two-factor-challenge', ['code' => $tfa->currentCode($secret)])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);

    // Disable ⇒ login is single-step again
    $this->actingAs($user)->delete('/settings/two-factor', ['password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});
