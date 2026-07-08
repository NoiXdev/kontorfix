<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function __construct(private TwoFactorAuthenticator $tfa) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/TwoFactorChallenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('login.id');
        if ($userId === null) {
            return redirect()->route('login');
        }

        // Wird der User zwischen Passwort- und Faktor-Schritt gelöscht: sauber zurück zum Login.
        $user = User::find($userId);
        if ($user === null) {
            $request->session()->forget('login.id');

            return redirect()->route('login');
        }

        // Account-gebundenes Rate-Limit auf die Session-User-ID (nicht die IP) — so lässt es
        // sich nicht durch rotierende X-Forwarded-For-Header aushebeln.
        $throttleKey = 'two-factor-challenge:'.$userId;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'code' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
            ]);
        }

        $valid = false;
        $field = 'code';
        if ($request->filled('code')) {
            $field = 'code';
            $timestamp = $this->tfa->verifyReturningTimestamp(
                $user->two_factor_secret,
                (string) $request->string('code'),
                $user->two_factor_last_timestamp,
            );

            if ($timestamp !== false) {
                // Verwendeten Zeitschritt festhalten → derselbe Code gilt kein zweites Mal.
                $user->forceFill(['two_factor_last_timestamp' => $timestamp])->save();
                $valid = true;
            }
        } elseif ($request->filled('recovery_code')) {
            $field = 'recovery_code';
            $submitted = (string) $request->string('recovery_code');
            if (in_array($submitted, $user->recoveryCodes(), true)) {
                $user->replaceRecoveryCode($submitted);
                $valid = true;
            }
        }

        if (! $valid) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([$field => __('Der Code ist ungültig.')]);
        }

        RateLimiter::clear($throttleKey);

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
