<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Three counters guard this endpoint, and only one of them is allowed to refuse a request.
 *
 *  - per (email, IP), 5/60 s, checked BEFORE the comparison. It may refuse, because its key
 *    carries the requester's own address: burning it costs the attacker a source address,
 *    not the account holder their way in.
 *  - per account, across every source address. It may NOT refuse. Anyone who knows an
 *    address can drive it, so a refusal here is an anonymous, targeted and indefinitely
 *    renewable lockout of an account that may hold no second factor and no other way in.
 *  - per source address, across every account — the credential-stuffing dimension the
 *    (email, IP) key misses entirely, since that key changes with every addressee. It may
 *    not refuse either: a shared office egress would otherwise be a lockout button for
 *    everyone behind it.
 *
 * The two counters that may not refuse buy time instead. Past a free allowance, a *failing*
 * attempt is held for a progressively longer interval before it is answered; a correct
 * password never enters that branch, so the holder pays nothing and is never denied. That
 * bounds a sequential attacker to roughly one guess per DELAY_CAP_MS however many addresses
 * they rotate through, where before they had 5/minute per address and no aggregate limit.
 *
 * A delay is server time, so it is itself an availability risk: an unbounded or unbudgeted
 * hold on /login is a way to park every PHP-FPM worker. Hence DELAY_CAP_MS, and hence
 * DELAY_SLOTS — at most that many failing logins are ever held at once instance-wide, and a
 * request that finds the pool full is answered immediately. The residual is stated plainly:
 * an attacker willing to run more concurrent connections than the slot pool gets undelayed
 * answers for the excess. Bounding *that* would mean holding connections without limit,
 * which on a synchronous worker pool is a denial of service wearing a hat. The concurrency
 * an attacker needs to reach it is loud, and Lockout fires once per burst for monitoring.
 */
class LoginRequest extends FormRequest
{
    /** Failures per (account, source address) before the request is refused outright. */
    private const ADDRESS_MAX_ATTEMPTS = 5;

    /** Failures against one account, from any address, that carry no penalty. */
    private const ACCOUNT_FREE_FAILURES = 10;

    /** Failures from one source address, against any account, that carry no penalty. */
    private const SOURCE_FREE_FAILURES = 20;

    /** Window both penalty counters decay over. */
    private const DECAY_SECONDS = 900;

    /** Added to the hold per failure past the free allowance. */
    private const DELAY_STEP_MS = 500;

    /** Ceiling for a single held response. */
    private const DELAY_CAP_MS = 5000;

    /** How many failing logins may be held at once, instance-wide. */
    private const DELAY_SLOTS = 4;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Checks the credentials WITH rate limiting, but does NOT log in.
     *
     * @throws ValidationException
     */
    public function validateCredentials(): User
    {
        $this->ensureIsNotRateLimited();

        /** @var User|null $user */
        $user = User::where('email', $this->string('email'))->first();

        // Hash::check also runs against a fixed dummy hash when there is no user — constant work,
        // so the response time doesn't reveal whether an account exists (timing enumeration).
        $hash = $user->password ?? '$2y$12$wOujnffIB7RK/vXl7tujy.x0A/eb3esFIi0X.CdsC9MUy5aS6cHBi';

        if (! Hash::check((string) $this->string('password'), $hash) || ! $user) {
            $this->penaliseFailure();

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->accountThrottleKey());

        return $user;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * Only the per-(email, IP) counter is consulted here, i.e. before the credential check —
     * see the class docblock for why the other two never refuse.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::ADDRESS_MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Record a failed attempt and make the next one more expensive.
     *
     * Reached only when the comparison has already failed, which is the whole point: the
     * account holder's correct password takes the other branch and is never slowed, never
     * queued and never refused by anything keyed on their account.
     */
    private function penaliseFailure(): void
    {
        RateLimiter::hit($this->throttleKey());

        $accountFailures = RateLimiter::hit($this->accountThrottleKey(), self::DECAY_SECONDS);
        $sourceFailures = RateLimiter::hit($this->sourceThrottleKey(), self::DECAY_SECONDS);

        if ($accountFailures === self::ACCOUNT_FREE_FAILURES + 1) {
            // Exactly once per burst: something is working through passwords for this
            // account from more addresses than the per-address limiter can see. Firing on
            // every subsequent guess would only drown the signal.
            event(new Lockout($this));
        }

        $delay = max(
            $this->delayMilliseconds($accountFailures, self::ACCOUNT_FREE_FAILURES),
            $this->delayMilliseconds($sourceFailures, self::SOURCE_FREE_FAILURES),
        );

        if ($delay > 0) {
            $this->holdWithinBudget($delay);
        }
    }

    /** Linear ramp past the allowance, flat at the cap. */
    private function delayMilliseconds(int $failures, int $freeAllowance): int
    {
        return (int) min(max($failures - $freeAllowance, 0) * self::DELAY_STEP_MS, self::DELAY_CAP_MS);
    }

    /**
     * Hold the response, but only if the instance can currently spare a worker for it.
     * Availability wins over the guessing bound: dropping the penalty leaves the attacker
     * where the previous release already had them, whereas holding past the budget takes
     * the login page down for everyone.
     */
    private function holdWithinBudget(int $milliseconds): void
    {
        $slot = $this->acquireDelaySlot();

        if ($slot === null) {
            return;
        }

        try {
            Sleep::for($milliseconds)->milliseconds();
        } finally {
            Cache::forget($slot);
        }
    }

    /**
     * Claim one of the fixed delay slots, or null when all are taken.
     *
     * add() is atomic, so two workers cannot take the same slot. The TTL is one full hold
     * plus a margin: a slot leaked by a worker killed mid-sleep frees itself instead of
     * disabling the delay for the rest of the cache's life.
     */
    private function acquireDelaySlot(): ?string
    {
        $ttl = (int) ceil(self::DELAY_CAP_MS / 1000) + 1;

        for ($slot = 0; $slot < self::DELAY_SLOTS; $slot++) {
            $key = 'login-delay-slot|'.$slot;

            if (Cache::add($key, 1, $ttl)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return $this->normalizedEmail().'|'.$this->ip();
    }

    /**
     * Account-scoped key — carries no IP, so it cannot be reset by moving to a fresh source
     * address.
     */
    public function accountThrottleKey(): string
    {
        return 'login-account|'.$this->normalizedEmail();
    }

    /**
     * Source-scoped key — carries no addressee, so it cannot be reset by moving to the next
     * account in a stuffing list. Deliberately not cleared on a successful login: one valid
     * account in the list must not buy back the penalty for the rest.
     */
    public function sourceThrottleKey(): string
    {
        return 'login-source|'.$this->ip();
    }

    /**
     * Both email-bearing keys use the same normalization as the account lookup: lower-casing
     * only. Str::transliterate would additionally fold accents, so failures against the
     * non-account "tïm@x.com" would land on "tim@x.com"'s counter — a way to attack a
     * counter belonging to an address the requester never names. Percent-encoding is
     * required on top: RateLimiter::cleanRateLimiterKey() runs htmlentities() and then
     * collapses "&iuml;" back to "i", which folds the same two addresses together
     * again unless the key reaches it as pure ASCII.
     */
    private function normalizedEmail(): string
    {
        return rawurlencode(Str::lower($this->string('email')));
    }
}
