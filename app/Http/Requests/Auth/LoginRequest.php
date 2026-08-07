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
     * Two counters. The per-address one is the usual burst brake. The account-scoped one
     * exists because the first key contains the IP: an attacker with a pool of genuinely
     * distinct source addresses stays under it forever and gets unlimited attempts against
     * a single account. It is deliberately much looser (20 per 15 minutes, against 5 per
     * minute) — it has to stay out of the way of a human mistyping their own password,
     * and because it is keyed on the target account alone, a tight limit here would be an
     * account-lockout DoS. The same model is already used by the 2FA challenge.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $limits = [
            [$this->throttleKey(), 5],
            [$this->accountThrottleKey(), self::ACCOUNT_MAX_ATTEMPTS],
        ];

        foreach ($limits as [$key, $maxAttempts]) {
            if (! RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                continue;
            }

            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    /**
     * Account-scoped throttle key — carries no IP, so it cannot be reset by moving to a
     * fresh source address.
     */
    public function accountThrottleKey(): string
    {
        return Str::transliterate('login-account|'.Str::lower($this->string('email')));
    }
}
