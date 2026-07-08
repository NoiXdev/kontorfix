<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DisableTwoFactorRequest;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorAuthenticator $tfa) {}

    public function show(Request $request): Response
    {
        $user = $request->user();

        $setup = null;
        if ($user->hasEnabledTwoFactor() && ! $user->hasConfirmedTwoFactor()) {
            $setup = [
                'qr' => $this->tfa->qrCodeDataUri(config('app.name'), $user->email, $user->two_factor_secret),
                'secret' => $user->two_factor_secret,
                'recoveryCodes' => $user->recoveryCodes(),
            ];
        }

        return Inertia::render('settings/TwoFactor', [
            'enabled' => $user->hasEnabledTwoFactor(),
            'confirmed' => $user->hasConfirmedTwoFactor(),
            'setup' => $setup,
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Bereits aktive 2FA nicht kommentarlos neu würfeln — das würde sie in einen
        // unbestätigten (effektiv faktorlosen) Zwischenzustand zurücksetzen.
        if ($user->hasConfirmedTwoFactor()) {
            throw ValidationException::withMessages([
                'two_factor' => __('Zwei-Faktor ist bereits aktiv. Bitte zuerst deaktivieren.'),
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => $this->tfa->generateSecret(),
            'two_factor_recovery_codes' => $this->tfa->generateRecoveryCodes(),
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestamp' => null,
        ])->save();

        return back();
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->hasEnabledTwoFactor() || ! $this->tfa->verify($user->two_factor_secret, (string) $request->string('code'))) {
            throw ValidationException::withMessages(['code' => __('Der Code ist ungültig.')]);
        }

        // Zeitschritt wird hier bewusst NICHT festgehalten: sonst würde ein Login unmittelbar
        // nach dem Bestätigen (gleiches Zeitfenster) fälschlich als Replay abgelehnt. Der
        // Replay-Schutz greift im Login-Challenge, wo er sicherheitsrelevant ist.
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return back()->with('success', 'Zwei-Faktor-Authentifizierung aktiviert.');
    }

    public function disable(DisableTwoFactorRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestamp' => null,
        ])->save();

        return back()->with('success', 'Zwei-Faktor-Authentifizierung deaktiviert.');
    }
}
