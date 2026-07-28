<?php

use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

it('allows passkey login even when the user has enforced two factor', function () {
    // Deliberate policy (v0.8): a passkey (with user verification enforced) is itself strong
    // multi-factor auth and replaces the TOTP step. This test locks in that decision —
    // if someone flips it to "enforce 2FA", this test will fail.
    $user = User::factory()->create();
    $tfa = app(TwoFactorAuthenticator::class);
    $user->forceFill([
        'two_factor_secret' => $tfa->generateSecret(),
        'two_factor_recovery_codes' => ['keepme-keepme'],
        'two_factor_confirmed_at' => now(),
    ])->save();

    $passkey = new Passkey([
        'name' => 'Test Key',
        'credential_id' => 'test-credential-id',
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
    $passkey->user_id = $user->id;
    $passkey->save();

    expect($user->hasConfirmedTwoFactor())->toBeTrue();
    expect(Passkeys::allowsLogin(request(), $passkey))->toBeTrue();
});
