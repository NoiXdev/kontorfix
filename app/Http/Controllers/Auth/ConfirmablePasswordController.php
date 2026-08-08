<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * This endpoint stopped being a formality when the credential and two-factor areas were
 * put behind `password.confirm`: it is now the wall between a stolen session and a
 * long-lived bearer credential. It therefore throttles, leaves a trace, and checks the
 * password against the *session owner* rather than re-resolving them by email.
 */
class ConfirmablePasswordController extends Controller
{
    /** Failed attempts allowed from one source address against one session. */
    private const MAX_ATTEMPTS = 6;

    /** Failed attempts allowed against one account across every source address. */
    private const ACCOUNT_MAX_ATTEMPTS = 20;

    /** Window both counters decay over. */
    private const DECAY_SECONDS = 900;

    /**
     * Show the confirm password page.
     *
     * A password is not the only way to prove "still you", and for some accounts it is
     * not a way at all: OIDC-provisioned and admin-invited users hold a random hash
     * nobody knows, and a passkey-only user should not be asked for a password in the
     * first place. The screen therefore advertises both alternatives.
     */
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('auth/ConfirmPassword', [
            // `passkey.confirm` is ungated and already satisfies this gate server-side.
            'canUsePasskey' => $user->hasPasskeysEnabled(),
            'canRequestPasswordLink' => filled($user->email),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Mail the session owner a link to set a password — the escape hatch for an account
     * whose owner never knew one. The address is never taken from the request: it is the
     * authenticated user's own, so this cannot be used to spray reset mail at others.
     */
    public function sendPasswordLink(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (filled($user->email)) {
            // The broker throttles per address on its own (auth.passwords.users.throttle).
            Password::sendResetLink(['email' => $user->email]);
        }

        // Same wording either way: a mailbox-less account learns nothing extra, and there
        // is nothing the owner could do about it here anyway.
        return back()->with('status', __('Wir haben dir einen Link zum Setzen eines Passworts geschickt, sofern ein Postfach hinterlegt ist.'));
    }

    /**
     * Confirm the user's password.
     */
    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->ensureIsNotRateLimited($request, $user);

        if (! $this->passwordMatches($user, $request->input('password'))) {
            $this->recordFailure($request, $user);

            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        RateLimiter::clear($this->throttleKey($request, $user));
        RateLimiter::clear($this->accountThrottleKey($user));

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Compare against the already-authenticated principal's own hash. The scaffolded
     * version called `Auth::validate(['email' => $user->email, …])`, which re-resolves
     * the user by a nullable, mutable identifier: `where('email', null)` is rewritten by
     * Eloquent to `where "email" is null`, so an account without a mailbox would be
     * confirmed by *another* mailbox-less account's password.
     */
    private function passwordMatches(User $user, mixed $password): bool
    {
        if (! is_string($password) || $password === '' || ! is_string($user->password)) {
            return false;
        }

        return Hash::check($password, $user->password);
    }

    /**
     * Only the per-address counter is consulted before the comparison. Its key carries
     * the requester's own IP, so burning it costs them their source address. The
     * account-scoped counter is deliberately evaluated *after* a failed comparison
     * (see recordFailure) — a counter anyone holding a session could burn must never be
     * able to refuse the owner's correct password, which is the lockout `65be613`
     * produced on the login path.
     *
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(Request $request, User $user): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request, $user), self::MAX_ATTEMPTS)) {
            return;
        }

        $this->throwThrottleResponse($request, $this->throttleKey($request, $user));
    }

    /**
     * Count the failure, leave a trace, and answer with the throttle once the account
     * limit is reached. `Auth::validate()` fires no event and writes nothing, so an
     * unlimited password oracle behind a stolen session used to leave no record at all.
     *
     * @throws ValidationException
     */
    private function recordFailure(Request $request, User $user): void
    {
        RateLimiter::hit($this->throttleKey($request, $user), self::DECAY_SECONDS);
        RateLimiter::hit($this->accountThrottleKey($user), self::DECAY_SECONDS);

        event(new Failed('web', $user, ['email' => $user->email]));

        Log::warning('Password confirmation failed.', [
            'user_id' => $user->getKey(),
            'ip' => $request->ip(),
        ]);

        if (RateLimiter::tooManyAttempts($this->accountThrottleKey($user), self::ACCOUNT_MAX_ATTEMPTS)) {
            $this->throwThrottleResponse($request, $this->accountThrottleKey($user));
        }
    }

    /**
     * @throws ValidationException
     */
    private function throwThrottleResponse(Request $request, string $key): never
    {
        event(new Lockout($request));

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'password' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(Request $request, User $user): string
    {
        return 'confirm-password|'.$user->getKey().'|'.$request->ip();
    }

    /** Carries no IP, so it cannot be reset by moving to a fresh source address. */
    private function accountThrottleKey(User $user): string
    {
        return 'confirm-password-account|'.$user->getKey();
    }
}
