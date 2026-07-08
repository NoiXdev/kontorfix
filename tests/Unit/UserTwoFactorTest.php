<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports two factor state and encrypts the secret at rest', function () {
    $user = User::factory()->create();
    expect($user->hasEnabledTwoFactor())->toBeFalse();
    expect($user->hasConfirmedTwoFactor())->toBeFalse();

    $user->forceFill([
        'two_factor_secret' => 'PLAINSECRET',
        'two_factor_recovery_codes' => ['aaaa-bbbb', 'cccc-dddd'],
    ])->save();

    expect($user->hasEnabledTwoFactor())->toBeTrue();
    expect($user->hasConfirmedTwoFactor())->toBeFalse();

    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue();

    expect($user->fresh()->recoveryCodes())->toBe(['aaaa-bbbb', 'cccc-dddd']);
    $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
    expect($raw)->not->toBe('PLAINSECRET');

    $user->replaceRecoveryCode('aaaa-bbbb');
    expect($user->fresh()->recoveryCodes())->toBe(['cccc-dddd']);
});
