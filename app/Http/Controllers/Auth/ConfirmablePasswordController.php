<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\PasswordAttemptLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * This endpoint stopped being a formality when the credential and two-factor areas were
 * put behind `password.confirm`: it is now the wall between a stolen session and a
 * long-lived bearer credential. It therefore throttles, leaves a trace, and checks the
 * password against the *session owner* rather than re-resolving them by email.
 *
 * All three live in PasswordAttemptLimiter rather than here, because this endpoint is only
 * one of four that resolve the same hash — see that class for why sharing the buckets is
 * the whole point.
 */
class ConfirmablePasswordController extends Controller
{
    public function __construct(private readonly PasswordAttemptLimiter $limiter) {}

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

        if (($refusal = $this->limiter->refusalReason($request, $user)) !== null) {
            throw ValidationException::withMessages(['password' => $refusal]);
        }

        if (! $this->limiter->matches($user, $request->input('password'))) {
            throw ValidationException::withMessages([
                'password' => $this->limiter->recordFailure($request, $user) ?? __('auth.password'),
            ]);
        }

        $this->limiter->clear($request, $user);

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
