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

    /** Prüft einen 6-stelligen Code gegen das Secret (±1 Zeitfenster Toleranz). */
    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $code, 1);
    }

    /** Aktueller OTP-Code — nur für Tests / Debugging. */
    public function currentCode(string $secret): string
    {
        return $this->engine->getCurrentOtp($secret);
    }

    /** Inline-SVG-QR als data:-URI (kein externer Request, CSP-sicher). */
    public function qrCodeDataUri(string $company, string $holder, string $secret): string
    {
        $svg = $this->engine->getQRCodeInline($company, $holder, $secret);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Acht einmalige Recovery-Codes im Format xxxxxxxx-xxxxxxxx.
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
