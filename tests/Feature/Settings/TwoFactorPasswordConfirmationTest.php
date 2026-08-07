<?php

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;

// Enrolling a second factor is a credential-changing operation: whoever controls the
// authenticator controls every future login. A stolen session must therefore not be
// enough — the password has to be re-proven, exactly as for `settings/passkeys`.
it('requires password confirmation to view the two factor settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/two-factor')
        ->assertRedirect(route('password.confirm'));
});

it('requires password confirmation to enable two factor', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/two-factor/enable')
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->hasEnabledTwoFactor())->toBeFalse();
});

it('requires password confirmation to confirm two factor', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => app(TwoFactorAuthenticator::class)->generateSecret(),
        'two_factor_recovery_codes' => ['keepme0-keepme0'],
    ])->save();

    $code = app(TwoFactorAuthenticator::class)->currentCode($user->two_factor_secret);

    $this->actingAs($user)->post('/settings/two-factor/confirm', ['code' => $code])
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->hasConfirmedTwoFactor())->toBeFalse();
});

it('lets a user with a freshly confirmed password enable two factor', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/settings/two-factor/enable')
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->fresh()->hasEnabledTwoFactor())->toBeTrue();
});
