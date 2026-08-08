<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Failed attempts allowed against one account, across every source address. */
    private const ACCOUNT_MAX_ATTEMPTS = 20;

    /** Window the account-scoped counter decays over. */
    private const ACCOUNT_DECAY_SECONDS = 900;

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
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->accountThrottleKey(), self::ACCOUNT_DECAY_SECONDS);

            // Past the account limit the failure is answered by the throttle rather than
            // the credential check. The counter never sees a correct password, so the
            // account holder is not caught by it.
            if (RateLimiter::tooManyAttempts($this->accountThrottleKey(), self::ACCOUNT_MAX_ATTEMPTS)) {
                $this->throwThrottleResponse($this->accountThrottleKey());
            }

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
     * Only the per-address counter is consulted here, i.e. before the credential check.
     * Its key contains the requester's own IP, so burning it costs the attacker their
     * own source address and never the victim's ability to log in.
     *
     * The account-scoped counter is deliberately NOT checked here. It is keyed on the
     * target account alone — that is the whole point, an attacker with a pool of source
     * addresses must not get unlimited attempts against one account — but a counter that
     * anyone can burn by naming an email address must never be able to refuse the
     * account holder's *correct* password: that would be an anonymous, targeted and
     * indefinitely renewable lockout, and a password-only account has no other way in.
     * It is therefore evaluated in validateCredentials() after the hash comparison and
     * applies to failures only: a wrong password past the limit is answered with the
     * throttle message (and raises Lockout for monitoring), a right one logs in and
     * clears both counters.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $this->throwThrottleResponse($this->throttleKey());
    }

    /**
     * Raise Lockout and fail the request with the standard "try again later" message.
     *
     * @throws ValidationException
     */
    private function throwThrottleResponse(string $key): never
    {
        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return $this->normalizedEmail().'|'.$this->ip();
    }

    /**
     * Account-scoped throttle key — carries no IP, so it cannot be reset by moving to a
     * fresh source address.
     */
    public function accountThrottleKey(): string
    {
        return 'login-account|'.$this->normalizedEmail();
    }

    /**
     * Both keys use the same normalization as the account lookup: lower-casing only.
     * Str::transliterate would additionally fold accents, so failures against the
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
