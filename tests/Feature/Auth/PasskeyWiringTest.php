<?php

use App\Models\User;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;

it('makes the user a passkey user with a passkeys relation', function () {
    $user = User::factory()->create();

    expect($user)->toBeInstanceOf(PasskeyUser::class);
    expect($user->hasPasskeysEnabled())->toBeFalse();

    Passkey::forceCreate([
        'user_id' => $user->getKey(),
        'name' => 'MacBook',
        'credential_id' => 'cred-'.$user->getKey(),
        'credential' => ['foo' => 'bar'],
    ]);

    expect($user->fresh()->hasPasskeysEnabled())->toBeTrue();
    expect($user->fresh()->passkeys)->toHaveCount(1);
});

it('derives a stable, non-pii user handle', function () {
    $user = User::factory()->create();

    $handle = $user->getPasskeyUserHandle();
    expect($handle)->toBe($user->fresh()->getPasskeyUserHandle());
    expect($handle)->not->toContain($user->email);
});

it('registers the package passkey routes', function () {
    expect(route('passkey.login-options'))->toContain('/passkeys/login/options');
    expect(route('passkey.login'))->toContain('/passkeys/login');
    expect(route('passkey.registration-options'))->toContain('/user/passkeys/options');
});
