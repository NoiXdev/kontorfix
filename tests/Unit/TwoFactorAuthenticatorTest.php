<?php

use App\Services\Auth\TwoFactorAuthenticator;

it('generates a secret, verifies its current code and rejects a wrong one', function () {
    $svc = app(TwoFactorAuthenticator::class);
    $secret = $svc->generateSecret();

    expect($secret)->toBeString()->not->toBeEmpty();

    $current = $svc->currentCode($secret);
    expect($svc->verify($secret, $current))->toBeTrue();
    expect($svc->verify($secret, '000000'))->toBeFalse();
});

it('produces an inline svg qr code data uri', function () {
    $svc = app(TwoFactorAuthenticator::class);
    $secret = $svc->generateSecret();

    $qr = $svc->qrCodeDataUri('Kontorfix', 'user@example.test', $secret);
    expect($qr)->toStartWith('data:image/svg+xml');
});

it('generates eight unique recovery codes', function () {
    $codes = app(TwoFactorAuthenticator::class)->generateRecoveryCodes();
    expect($codes)->toHaveCount(8);
    expect(array_unique($codes))->toHaveCount(8);
});
