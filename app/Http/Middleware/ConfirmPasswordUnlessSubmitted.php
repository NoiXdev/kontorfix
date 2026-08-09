<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * The escape hatch for the two routes that prove the password inline.
 *
 * `DELETE /settings/two-factor` and `DELETE /settings/profile` demand the current password
 * in the payload. For most accounts that is the right shape — the proof happens on the
 * request that acts, with no window to inherit. For the accounts this application creates
 * without one it is a dead end: an OIDC-provisioned, admin-invited or passkey-only user
 * holds a random hash nobody knows, so they could *enable* a second factor (a passkey
 * satisfies `password.confirm`) and never disable it, and could never delete their own
 * account. Both are availability defects with no attacker behind them, and the gate next
 * door already offers exactly the two alternatives they need.
 *
 * So: submit a password and nothing changes, it is compared as before. Submit none and the
 * request is sent to the confirmation screen, which accepts a passkey assertion or mails a
 * set-password link. `ConvertEmptyStringsToNull` runs first, so an empty field and an
 * absent one are the same thing here.
 *
 * FRESH_CONFIRMATION_SECONDS rather than the shared `auth.password_timeout`: these two
 * routes used to require the password on the acting request itself, and replacing that with
 * "somebody confirmed a quarter of an hour ago" would be a real weakening. Five minutes is
 * close to the previous behaviour and is the same freshness the address change asks for.
 */
class ConfirmPasswordUnlessSubmitted extends RequirePassword
{
    public const FRESH_CONFIRMATION_SECONDS = 300;

    /**
     * @param  string|null  $redirectToRoute
     * @param  string|int|null  $passwordTimeoutSeconds
     * @return mixed
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        if (filled($request->input('password'))) {
            return $next($request);
        }

        return parent::handle(
            $request,
            $next,
            $redirectToRoute,
            $passwordTimeoutSeconds ?? self::FRESH_CONFIRMATION_SECONDS,
        );
    }

    /**
     * The same question the middleware answers, for the form requests behind it.
     *
     * They ask because a route that lost this middleware must fall back to demanding the
     * password, not to accepting an empty one: the two routes here delete an account and
     * strip a second factor, and neither may ever run on no proof at all.
     */
    public static function confirmedRecently(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmedAt > 0
            && Date::now()->unix() - $confirmedAt <= self::FRESH_CONFIRMATION_SECONDS;
    }
}
