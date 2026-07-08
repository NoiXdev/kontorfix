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
        return $this->verifyReturningTimestamp($secret, $code, null) !== false;
    }

    /**
     * Wie verify(), gibt aber den verwendeten Zeitschritt zurück (oder false).
     * Mit $lastTimestamp werden Codes <= diesem Zeitschritt abgelehnt (Replay-Schutz):
     * ein bereits genutzter Code kann im ±1-Fenster nicht ein zweites Mal gelten.
     */
    public function verifyReturningTimestamp(string $secret, string $code, ?int $lastTimestamp): int|false
    {
        // google2fa gibt bei oldTimestamp === null ein bool `true` (statt des Zeitschritts)
        // zurück — daher 0 statt null übergeben: 0 < jeder echte Zeitschritt, also erhalten
        // wir immer den tatsächlichen int-Zeitschritt zum Persistieren.
        $result = $this->engine->verifyKeyNewer($secret, $code, $lastTimestamp ?? 0, 1);

        return $result === false ? false : (int) $result;
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
