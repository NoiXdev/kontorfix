<?php

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;

function enrolledUser(): User
{
    $user = User::factory()->create();
    $tfa = app(TwoFactorAuthenticator::class);
    $user->forceFill([
        'two_factor_secret' => $tfa->generateSecret(),
        'two_factor_recovery_codes' => ['keepme-keepme', 'useme00-useme00'],
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

it('does not log a 2fa user in on password; it redirects to the challenge', function () {
    $user = enrolledUser();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id);
});

it('completes login with a valid totp code', function () {
    $user = enrolledUser();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $code = app(TwoFactorAuthenticator::class)->currentCode($user->two_factor_secret);
    $this->post('/two-factor-challenge', ['code' => $code])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(session('login.id'))->toBeNull();
});

it('completes login with a recovery code and consumes it', function () {
    $user = enrolledUser();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->post('/two-factor-challenge', ['recovery_code' => 'useme00-useme00'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->recoveryCodes())->toBe(['keepme-keepme']);
});

it('rejects an invalid code and stays on the challenge', function () {
    $user = enrolledUser();
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->from(route('two-factor.login'))
        ->post('/two-factor-challenge', ['code' => '000000'])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('redirects to login when hitting the challenge without a pending login', function () {
    $this->get('/two-factor-challenge')->assertRedirect(route('login'));
});

it('logs a normal user in directly without a challenge', function () {
    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});
