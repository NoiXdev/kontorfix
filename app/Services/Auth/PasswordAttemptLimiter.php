<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One brake for every endpoint that compares a submitted string against the session
 * owner's password hash.
 *
 * `POST /confirm-password` used to own this logic, which made the brake worth exactly as
 * much as its least-guarded sibling: `DELETE /settings/two-factor`, `PUT /settings/password`
 * and `DELETE /settings/profile` resolve the *same* hash through a bare `current_password`
 * rule, and an attacker holding a stolen session simply moved there once the confirm-password
 * bucket was exhausted. The counters therefore have to be keyed per principal and shared
 * across every surface that performs the comparison — which is what this class is for. The
 * key names are unchanged so the two buckets stay the ones the confirm-password screen
 * already fills.
 *
 * Both counters refuse, and both refuse *before* the comparison. A refusal handed out
 * afterwards refuses nothing — the caller learns whether the guess was right either way —
 * which is exactly what the account counter used to do: it swapped the error string and
 * bounded nothing, so past the per-(user, IP) bucket an attacker rotated source address
 * (sessions are not pinned to one) and guessed without any aggregate limit at all.
 *
 * On `POST /login` that same construction would be an anonymous lockout, and this class
 * deliberately does *not* copy the admission rule that solves it there. Two reasons, and
 * the second is the important one:
 *
 *  - the KnownClients marker the login throttle uses to tell the holder from an attacker
 *    is worthless here. It is a cookie on the same origin as the session cookie, so every
 *    way of obtaining one obtains the other; it would wave the attacker through.
 *  - it is not needed. These four routes are reachable only with a session for the account
 *    being guessed at, so the account bucket can only ever be burned by the owner or by
 *    somebody who already holds the owner's session. That is not an anonymous actor and
 *    not a low-privileged one, which is the entire reason a refusal is safe here and is
 *    not safe on the login form.
 *
 * What the owner pays: up to DECAY_SECONDS with no password confirmation — no new tokens,
 * no two-factor changes, no self-deletion — while somebody who already occupies their
 * session guesses. They are not evicted from anything they already hold, and the way out
 * is a password reset, which also evicts the attacker.
 */
class PasswordAttemptLimiter
{
    /** Failed attempts allowed from one source address against one account. */
    public const MAX_ATTEMPTS = 6;

    /** Failed attempts allowed against one account across every source address. */
    public const ACCOUNT_MAX_ATTEMPTS = 20;

    /** Window both counters decay over. */
    public const DECAY_SECONDS = 900;

    /**
     * Whether this attempt is refused before the comparison even runs.
     *
     * @return string|null the message to answer with, or null to proceed
     */
    public function refusalReason(Request $request, User $user): ?string
    {
        $addressKey = $this->addressKey($request, $user);

        if (RateLimiter::tooManyAttempts($addressKey, self::MAX_ATTEMPTS)) {
            return $this->throttleMessage($request, $addressKey);
        }

        // The address-independent bound. Without it the per-(user, IP) bucket above is
        // defeated by moving to the next source address, which costs an attacker who
        // already holds the session nothing at all.
        $accountKey = $this->accountKey($user);

        if (RateLimiter::tooManyAttempts($accountKey, self::ACCOUNT_MAX_ATTEMPTS)) {
            return $this->throttleMessage($request, $accountKey);
        }

        return null;
    }

    /**
     * Compare against the already-authenticated principal's own hash.
     *
     * Never re-resolve the user by email here: `where('email', null)` is rewritten by
     * Eloquent to `where "email" is null`, so an account without a mailbox would be
     * confirmed by *another* mailbox-less account's password.
     */
    public function matches(User $user, mixed $password): bool
    {
        if (! is_string($password) || $password === '' || ! is_string($user->password)) {
            return false;
        }

        return Hash::check($password, $user->password);
    }

    /**
     * Count the failure and leave a trace. `current_password` and `Auth::validate()` both
     * fire no event and write nothing, so an unlimited password oracle behind a stolen
     * session used to leave no record at all.
     *
     * @return string|null the throttle message for the attempt that crosses the account
     *                     limit; every attempt after it is refused by refusalReason()
     *                     before the comparison ever runs
     */
    public function recordFailure(Request $request, User $user): ?string
    {
        RateLimiter::hit($this->addressKey($request, $user), self::DECAY_SECONDS);
        RateLimiter::hit($this->accountKey($user), self::DECAY_SECONDS);

        event(new Failed('web', $user, ['email' => $user->email]));

        Log::warning('Password confirmation failed.', [
            'user_id' => $user->getKey(),
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        if (! RateLimiter::tooManyAttempts($this->accountKey($user), self::ACCOUNT_MAX_ATTEMPTS)) {
            return null;
        }

        return $this->throttleMessage($request, $this->accountKey($user));
    }

    /** Called after a successful comparison — a proven owner owes nothing to either bucket. */
    public function clear(Request $request, User $user): void
    {
        RateLimiter::clear($this->addressKey($request, $user));
        RateLimiter::clear($this->accountKey($user));
    }

    private function throttleMessage(Request $request, string $key): string
    {
        event(new Lockout($request));

        $seconds = RateLimiter::availableIn($key);

        return trans('auth.throttle', [
            'seconds' => $seconds,
            'minutes' => ceil($seconds / 60),
        ]);
    }

    public function addressKey(Request $request, User $user): string
    {
        return 'confirm-password|'.$user->getKey().'|'.$request->ip();
    }

    /** Carries no IP, so it cannot be reset by moving to a fresh source address. */
    public function accountKey(User $user): string
    {
        return 'confirm-password-account|'.$user->getKey();
    }
}
