<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Rules\CurrentPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Password', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Throttled and logged like every other surface that resolves this hash.
            'current_password' => ['required', new CurrentPassword],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        // A password change is what a user reaches for when they suspect someone else is
        // in their account, so it has to evict that someone. The new hash alone already
        // invalidates every other session via the AuthenticateSession middleware
        // (bootstrap/app.php); calling this additionally re-issues the recaller cookie for
        // the device doing the change, so the user is not logged out of their own browser,
        // and it fires OtherDeviceLogout for the audit log.
        //
        // Deliberately *not* revoked here: registry tokens, API keys and passkeys. They are
        // named credentials the user can see and revoke one by one in settings, minting
        // them now requires password.confirm, and registry tokens in particular are machine
        // credentials wired into CI — silently destroying them would turn a routine password
        // rotation into an outage. This matches how comparable package registries behave.
        Auth::logoutOtherDevices($validated['password']);

        return back();
    }
}
