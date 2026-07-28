<?php

namespace App\Services\Auth;

use Illuminate\Support\Str;
use PragmaRX\Google2FAQRCode\Google2FA;

class TwoFactorAuthenticator
{
    public function __construct(private Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /** Verifies a 6-digit code against the secret (±1 time-step tolerance). */
    public function verify(string $secret, string $code): bool
    {
        return $this->verifyReturningTimestamp($secret, $code, null) !== false;
    }

    /**
     * Like verify(), but returns the time step that was used (or false).
     * With $lastTimestamp, codes <= this time step are rejected (replay protection):
     * a code that was already used cannot be valid a second time within the ±1 window.
     */
    public function verifyReturningTimestamp(string $secret, string $code, ?int $lastTimestamp): int|false
    {
        // google2fa returns a bool `true` (instead of the time step) when oldTimestamp
        // === null — so we pass 0 instead of null: 0 < any real time step, so we always
        // get the actual int time step to persist.
        $result = $this->engine->verifyKeyNewer($secret, $code, $lastTimestamp ?? 0, 1);

        return $result === false ? false : (int) $result;
    }

    /** Current OTP code — for tests / debugging only. */
    public function currentCode(string $secret): string
    {
        return $this->engine->getCurrentOtp($secret);
    }

    /** Inline SVG QR code as a data: URI (no external request, CSP-safe). */
    public function qrCodeDataUri(string $company, string $holder, string $secret): string
    {
        $svg = $this->engine->getQRCodeInline($company, $holder, $secret);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Eight single-use recovery codes in the format xxxxxxxx-xxxxxxxx.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => Str::lower(Str::random(8).'-'.Str::random(8)))
            ->all();
    }
}
