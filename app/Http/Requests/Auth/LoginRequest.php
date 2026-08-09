<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Auth\KnownClients;
use Illuminate\Auth\Events\Failed;
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
 * The property this endpoint guarantees:
 *
 *   Past a free allowance, the instance never answers more than DELAY_SLOTS penalised
 *   guesses per DELAY_CAP_MS — about 0.8 per second — no matter how many source addresses
 *   or concurrent connections the attacker brings; and no anonymous traffic can stop the
 *   account holder from logging in from a browser they have used before.
 *
 * Getting both halves of that at once is the whole difficulty, and two earlier attempts
 * each surrendered one of them. The constraint is structural: a refusal has to be decided
 * *before* the comparison, or it refuses nothing an attacker cares about — they already
 * know a guess was wrong from not being logged in. But a refusal decided before the
 * comparison cannot tell the holder from the attacker, so a counter keyed on the account
 * becomes an anonymous, targeted, indefinitely renewable lockout. Holding the connection
 * instead sidesteps the lockout and buys real time from a sequential attacker, but a hold
 * is a worker, and a bound that may only spend a fixed number of workers evaporates the
 * moment the attacker brings more connections than that.
 *
 * The tie is broken by the one thing the holder has before the comparison and an anonymous
 * attacker does not: a marker their browser picked up the last time this account signed in
 * there (see KnownClients). Three counters and one admission rule:
 *
 *  - per (email, IP), 5/60 s, checked before the comparison. It may refuse outright: its
 *    key carries the requester's own address, so burning it costs the attacker a source
 *    address, not the holder their way in.
 *  - per account, across every source address, and per source address, across every
 *    account — the two dimensions the (email, IP) key cannot see. Neither refuses on its
 *    own count. They set a *penalty*, a progressive hold applied to the failing attempt.
 *  - admission: a request that owes a penalty and comes from a browser this account has
 *    never signed in from must claim one of DELAY_SLOTS before its password is compared,
 *    and is refused when the pool is full. That is what makes the pool a pace-setter
 *    instead of an escape hatch — the previous release answered the excess immediately,
 *    at full bcrypt speed, so four cheap connections switched the brake off for everyone.
 *
 * What that costs whom:
 *
 *  - a recognised browser is never held and never refused, whatever the counters say. The
 *    holder's correct password is answered at once even mid-attack.
 *  - a *first* login from a new browser, while an attacker is saturating the pool against
 *    that same account, is refused. This is the residual, and it is deliberate: it is the
 *    price of the bound, it costs the attacker continuous traffic to sustain, and it is
 *    not a dead end — completing a password reset marks the browser (that endpoint is
 *    throttled per source address only, precisely so an attacker cannot deny it).
 *  - workers held are still capped at DELAY_SLOTS × DELAY_CAP_MS.
 *
 * What is still open, plainly: guesses inside the free allowance are not paced at all, so
 * an attacker with unlimited addresses still gets ACCOUNT_FREE_FAILURES tries per account
 * per DECAY_SECONDS for free, and credential stuffing — one guess against each of many
 * accounts — is bounded only per source address. Refusing on the source counter would fix
 * the second and is deliberately not done: with TRUSTED_PROXIES misconfigured (its shipped
 * default is documented as too broad) every user collapses onto one address and that
 * refusal becomes an instance-wide outage. Edge rate limiting is the control for that
 * dimension; LogAuthenticationEvent is what gives an operator something to act on.
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
     * The slot claimed for this request, held from before the comparison until the hold is
     * over — so the pool paces guesses rather than merely capping how many are slow.
     */
    private ?string $delaySlot = null;

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

        try {
            return $this->compareCredentials();
        } finally {
            $this->releaseDelaySlot();
        }
    }

    /**
     * @throws ValidationException
     */
    private function compareCredentials(): User
    {
        /** @var User|null $user */
        $user = User::where('email', $this->string('email'))->first();

        $hash = $user?->password;

        if (is_string($hash) && $hash !== '') {
            $matches = Hash::check((string) $this->string('password'), $hash);
        } else {
            // No account — or one whose hash nobody can match, as robots hold none. Burn
            // one hash so the response costs the same as a real comparison and the timing
            // does not reveal whether an account exists.
            //
            // Derived from the *active* hasher rather than pinned to a `$2y$12$…` literal.
            // The project publishes no config/hashing.php, so `BCRYPT_ROUNDS` and
            // `HASH_DRIVER` are live env knobs: a literal desynchronises from real hashes
            // the moment either moves — and `hashing.rehash_on_login` is true, so real
            // hashes migrate to the new cost while a literal stays put, widening the gap
            // over time. Worse, under `HASH_DRIVER=argon2id` ArgonHasher::check() throws on
            // a bcrypt literal, turning a missing account into a 500 next to a real
            // account's 302 — a perfect oracle in place of the timing one this closes.
            Hash::make(Str::random(40));
            $matches = false;
        }

        if (! $matches || ! $user) {
            $this->penaliseFailure($user);

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
        if (RateLimiter::tooManyAttempts($this->throttleKey(), self::ADDRESS_MAX_ATTEMPTS)) {
            event(new Lockout($this));

            $this->refuse(RateLimiter::availableIn($this->throttleKey()));
        }

        $this->ensureThePenaltyCanBePaid();
    }

    /**
     * Admission control for a guess that already owes a penalty.
     *
     * Runs before the comparison, because a refusal handed out afterwards refuses nothing:
     * the attacker has their answer either way. Only unrecognised browsers are ever
     * subject to it, which is what keeps it from being a lockout: the holder's own machine
     * carries a marker from its last successful sign-in and skips the queue entirely.
     *
     * @throws ValidationException
     */
    private function ensureThePenaltyCanBePaid(): void
    {
        if ($this->pendingDelay() <= 0) {
            return;
        }

        if (app(KnownClients::class)->recognises($this, (string) $this->string('email'))) {
            return;
        }

        $this->delaySlot = $this->acquireDelaySlot();

        if ($this->delaySlot === null) {
            // Every slot is spoken for. Refusing here is what converts the pool from a cap
            // on how many guesses are slow into a cap on how many guesses happen at all.
            // The retry hint is the length of one hold, not the counter's decay: the queue
            // is what is full, and it drains in that time.
            $this->refuse((int) ceil(self::DELAY_CAP_MS / 1000));
        }
    }

    /**
     * What this attempt would cost if it fails — evaluated before the hit, so admission
     * can be decided before the comparison rather than after it.
     */
    private function pendingDelay(): int
    {
        return max(
            $this->delayMilliseconds(RateLimiter::attempts($this->accountThrottleKey()) + 1, self::ACCOUNT_FREE_FAILURES),
            $this->delayMilliseconds(RateLimiter::attempts($this->sourceThrottleKey()) + 1, self::SOURCE_FREE_FAILURES),
        );
    }

    /**
     * One wording for every refusal on this endpoint. A throttled answer must look the
     * same for an address that has an account and one that does not, or the throttle
     * becomes the account-existence oracle the rest of this class is written to avoid.
     *
     * @throws ValidationException
     */
    private function refuse(int $seconds): never
    {
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
    private function penaliseFailure(?User $user): void
    {
        // The per-guess record. Auth::attempt() is never called on this path — the
        // comparison is a bare Hash::check — so nothing else raises it, and without it a
        // brute force reaching this line was invisible. LogAuthenticationEvent turns it
        // into a log line and deliberately reads no credentials out of it.
        event(new Failed('web', $user, ['email' => (string) $this->string('email')]));

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

        if ($delay > 0 && $this->delaySlot !== null) {
            Sleep::for($delay)->milliseconds();
        }
    }

    /** Linear ramp past the allowance, flat at the cap. */
    private function delayMilliseconds(int $failures, int $freeAllowance): int
    {
        return (int) min(max($failures - $freeAllowance, 0) * self::DELAY_STEP_MS, self::DELAY_CAP_MS);
    }

    /**
     * Claim one of the fixed delay slots, or null when all are taken.
     *
     * add() is atomic, so two workers cannot take the same slot. The TTL covers the
     * comparison plus one full hold: a slot leaked by a worker killed mid-sleep frees
     * itself instead of refusing every unrecognised login for the rest of the cache's
     * life. That direction matters — a leaked slot now denies rather than merely
     * un-delays, so the TTL is the thing keeping a crash from becoming an outage.
     */
    private function acquireDelaySlot(): ?string
    {
        $ttl = (int) ceil(self::DELAY_CAP_MS / 1000) + 5;

        for ($slot = 0; $slot < self::DELAY_SLOTS; $slot++) {
            $key = 'login-delay-slot|'.$slot;

            if (Cache::add($key, 1, $ttl)) {
                return $key;
            }
        }

        return null;
    }

    /** Give the slot back the moment this request is done with it, however it ends. */
    private function releaseDelaySlot(): void
    {
        if ($this->delaySlot !== null) {
            Cache::forget($this->delaySlot);
            $this->delaySlot = null;
        }
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
