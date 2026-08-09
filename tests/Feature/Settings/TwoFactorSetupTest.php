<?php

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;

beforeEach(function () {
    $this->user = User::factory()->create();
    // The two-factor enrollment routes sit behind `password.confirm`; these tests are
    // about the enrollment mechanics, so they start from a confirmed-password session.
    // The gate itself is covered in TwoFactorPasswordConfirmationTest.
    $this->withSession(['auth.password_confirmed_at' => time()]);
});

it('enables (unconfirmed) then confirms two factor with a valid code', function () {
    $this->actingAs($this->user)->post('/settings/two-factor/enable')->assertRedirect();

    $this->user->refresh();
    expect($this->user->hasEnabledTwoFactor())->toBeTrue();
    expect($this->user->hasConfirmedTwoFactor())->toBeFalse();
    expect($this->user->recoveryCodes())->toHaveCount(8);

    $this->actingAs($this->user)->get('/settings/two-factor')
        ->assertInertia(fn ($p) => $p->component('settings/TwoFactor')
            ->where('confirmed', false)
            ->has('setup.qr')->has('setup.secret')->has('setup.recoveryCodes'));

    $code = app(TwoFactorAuthenticator::class)->currentCode($this->user->two_factor_secret);
    $this->actingAs($this->user)->post('/settings/two-factor/confirm', ['code' => $code])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($this->user->fresh()->hasConfirmedTwoFactor())->toBeTrue();

    $this->actingAs($this->user)->get('/settings/two-factor')
        ->assertInertia(fn ($p) => $p->where('confirmed', true)->where('setup', null));
});

it('rejects confirmation with a wrong code', function () {
    $this->actingAs($this->user)->post('/settings/two-factor/enable');
    $this->actingAs($this->user)->post('/settings/two-factor/confirm', ['code' => '000000'])
        ->assertSessionHasErrors('code');
    expect($this->user->fresh()->hasConfirmedTwoFactor())->toBeFalse();
});

it('disables two factor only with the correct password', function () {
    $this->actingAs($this->user)->post('/settings/two-factor/enable');
    $code = app(TwoFactorAuthenticator::class)->currentCode($this->user->fresh()->two_factor_secret);
    $this->actingAs($this->user)->post('/settings/two-factor/confirm', ['code' => $code]);

    $this->actingAs($this->user)->delete('/settings/two-factor', ['password' => 'wrong'])
        ->assertSessionHasErrors('password');
    expect($this->user->fresh()->hasEnabledTwoFactor())->toBeTrue();

    $this->actingAs($this->user)->delete('/settings/two-factor', ['password' => 'password'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $fresh = $this->user->fresh();
    expect($fresh->hasEnabledTwoFactor())->toBeFalse();
    expect($fresh->two_factor_confirmed_at)->toBeNull();
});
