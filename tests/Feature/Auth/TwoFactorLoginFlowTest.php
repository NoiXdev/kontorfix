<?php

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;

it('runs the full enroll -> logout -> challenge -> login lifecycle', function () {
    $tfa = app(TwoFactorAuthenticator::class);
    $user = User::factory()->create();

    // Einrichten + bestätigen
    $this->actingAs($user)->post('/settings/two-factor/enable');
    $secret = $user->fresh()->two_factor_secret;
    $this->actingAs($user)->post('/settings/two-factor/confirm', ['code' => $tfa->currentCode($secret)])
        ->assertSessionHasNoErrors();
    expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue();

    // Ausloggen
    $this->post('/logout');
    $this->assertGuest();

    // Login mit Passwort ⇒ Challenge, noch nicht eingeloggt
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    // Challenge mit gültigem TOTP ⇒ eingeloggt
    $this->post('/two-factor-challenge', ['code' => $tfa->currentCode($secret)])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);

    // Deaktivieren ⇒ Login wieder einstufig
    $this->actingAs($user)->delete('/settings/two-factor', ['password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});
