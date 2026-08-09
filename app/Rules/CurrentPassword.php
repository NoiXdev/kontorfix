<?php

namespace App\Rules;

use App\Models\User;
use App\Services\Auth\PasswordAttemptLimiter;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;

/**
 * Drop-in replacement for the framework's `current_password` rule that goes through
 * PasswordAttemptLimiter.
 *
 * `current_password` resolves to a bare `$hasher->check($value, $guard->user()->getAuthPassword())`
 * with no counter, no event and no log line, which turned every route carrying it into an
 * unmetered password oracle for whoever already holds the session — and, worse, into a way
 * around the metered one on `POST /confirm-password`. Same comparison, same buckets, same
 * trace, wherever it is used.
 */
class CurrentPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $request = app(Request::class);
        $user = $request->user();

        if (! $user instanceof User) {
            $fail(trans('auth.password'));

            return;
        }

        $limiter = app(PasswordAttemptLimiter::class);

        if (($refusal = $limiter->refusalReason($request, $user)) !== null) {
            $fail($refusal);

            return;
        }

        if ($limiter->matches($user, $value)) {
            $limiter->clear($request, $user);

            return;
        }

        $fail($limiter->recordFailure($request, $user) ?? trans('auth.password'));
    }
}
