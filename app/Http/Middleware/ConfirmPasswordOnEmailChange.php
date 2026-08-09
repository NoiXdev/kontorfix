<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * `password.confirm` for the one field on the profile form that is a takeover primitive.
 *
 * The address is the account's recovery channel. Without this, a stolen session needs one
 * ungated PATCH to point it at the attacker's mailbox, then a reset link, and the account
 * is theirs permanently — with the owner evicted by the hash change and their own reset
 * mail delivered to the attacker. That single route walked around every gate on the
 * credential, passkey and two-factor surfaces, because none of them matter once the
 * attacker can simply become the account.
 *
 * Attached to `PATCH /settings/profile` and to `PUT|PATCH /admin/users/{user}`, which is
 * the other writer of `users.email` — a super-admin session pointing somebody else's reset
 * channel at an attacker's mailbox is the same primitive aimed elsewhere. The target is the
 * route's `{user}` where there is one, the caller otherwise.
 *
 * Deliberately narrower than putting `password.confirm` on the route itself: the display
 * name, the role and the home organization are not takeover primitives, and demanding a
 * password to fix a typo in a name is friction with no attacker behind it. Everything else is inherited from the framework
 * middleware — the timeout (`auth.password_timeout`), the 423 for JSON callers, and the
 * redirect to the confirmation screen, which is also the surface that offers a passkey or
 * a set-password link to accounts that hold no password anybody knows.
 */
class ConfirmPasswordOnEmailChange extends RequirePassword
{
    /**
     * @param  string|null  $redirectToRoute
     * @param  string|int|null  $passwordTimeoutSeconds
     * @return mixed
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        if (! $this->changesEmailAddress($request)) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }

    /**
     * Whether this request would move the account's recovery address.
     *
     * A payload the comparison cannot read (an array, a nested structure) counts as a
     * change: the validator will reject it anyway, and "unreadable" must never resolve to
     * "unchanged" on the gate's side of the decision.
     */
    private function changesEmailAddress(Request $request): bool
    {
        // The account whose address is at stake, which is not always the caller's own:
        // the super-admin directory moves other people's addresses, and that is the same
        // takeover primitive pointed at somebody else.
        $target = $request->route('user');
        $target = $target instanceof User ? $target : $request->user();

        if ($target === null || ! $request->has('email')) {
            return false;
        }

        $submitted = $request->input('email');

        if (! is_string($submitted)) {
            return true;
        }

        return Str::lower(trim($submitted)) !== Str::lower((string) $target->email);
    }
}
