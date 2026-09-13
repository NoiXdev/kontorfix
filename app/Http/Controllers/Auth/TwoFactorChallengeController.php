<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    /**
     * The total number of wrong second factors one account tolerates per day.
     *
     * The five-per-minute limiter next to it is a RATE, not a ceiling: it rolls, so a
     * patient attacker simply waits out each window. With TOTP's ±1 step tolerance three
     * codes are valid at any moment, which puts a single guess at roughly 3·10⁻⁶ — five a
     * minute is about 7,200 a day and a median near a month against someone who already
     * holds the password. A hundred a day moves that median past six years while leaving
     * far more room than a person who keeps mistyping their app will ever need.
     *
     * A daily limiter rather than a lockout flag on the account, deliberately: the
     * attacker HAS the password and can reach this endpoint at will, so a state that only
     * an operator can clear would hand them a reliable way to lock the owner out. This one
     * heals on its own.
     */
    public const DAILY_ATTEMPT_CAP = 100;

    private const DAILY_DECAY_SECONDS = 86400;

    public function __construct(private TwoFactorAuthenticator $tfa) {}

    /**
     * Keyed on the account, not on the challenge session.
     *
     * A session-scoped counter would be no ceiling at all: whoever is guessing holds the
     * password, so they can start a fresh login and get a fresh session whenever a counter
     * inconveniences them.
     */
    public static function dailyThrottleKey(string $userId): string
    {
        return 'two-factor-challenge-daily:'.$userId;
    }

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

        // If the user is deleted between the password and factor step: go back to login cleanly.
        $user = User::find($userId);
        if ($user === null) {
            $request->session()->forget('login.id');

            return redirect()->route('login');
        }

        // Account-bound rate limit on the session user ID (not the IP) — this can't
        // be circumvented by rotating X-Forwarded-For headers.
        $throttleKey = 'two-factor-challenge:'.$userId;
        $dailyKey = self::dailyThrottleKey((string) $userId);

        foreach ([[$throttleKey, 5], [$dailyKey, self::DAILY_ATTEMPT_CAP]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $seconds = RateLimiter::availableIn($key);

                throw ValidationException::withMessages([
                    'code' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
                ]);
            }
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
                // Record the used time step → the same code is not valid a second time.
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
            // The per-guess record. Nothing else raises it on this path — Auth::attempt()
            // is never called here — so without it a brute force against the SECOND factor
            // was invisible to the application and to the account holder alike, while the
            // first factor has been logged since LogAuthenticationEvent shipped. The
            // submitted code is deliberately not part of the payload: a recovery code is a
            // long-lived secret, and Failed::$credentials is exactly the array that
            // listener refuses to read out for the same reason.
            event(new Failed('web', $user, ['email' => $user->email]));

            RateLimiter::hit($throttleKey);
            $dailyFailures = RateLimiter::hit($dailyKey, self::DAILY_DECAY_SECONDS);

            if ($dailyFailures === 5) {
                // Exactly once per burst, on the guess that spends the per-minute
                // allowance — five is the last failure that still reaches this branch,
                // since the sixth request is refused by the limiter above before anything
                // is compared. Past this point somebody is working through codes rather
                // than mistyping one. Firing on every subsequent refusal would only drown
                // the signal, and the refusal is repeatable, so a line per request would be
                // a log-amplification primitive. The daily counter is what makes this fire
                // once rather than once a minute: it does not roll off with the window.
                event(new Lockout($request));
            }

            throw ValidationException::withMessages([$field => __('Der Code ist ungültig.')]);
        }

        RateLimiter::clear($throttleKey);
        RateLimiter::clear($dailyKey);

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');

        abort_if($user->isRobot(), 403, 'Robot-Accounts können sich nicht interaktiv anmelden.');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
