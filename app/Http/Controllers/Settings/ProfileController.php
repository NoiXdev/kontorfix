<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Rules\CurrentPassword;
use App\Services\Mail\MailManager;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     *
     * Changing the address invalidates the verification: the new mailbox is unproven, and
     * `verified` gates the dashboard and the portal. That is only safe while a challenge
     * can actually be delivered, so the state is never cleared without one arriving:
     *
     *  - no deliverable transport (the wizard's `log` default) — the stamp is kept. Such
     *    an instance has no email-verification capability at all; every account it
     *    provisions is stamped verified on creation. Clearing it here would lock the user
     *    out of two surfaces behind a mail that only ever reaches storage/logs.
     *  - the transport is real but the send fails — the whole change is rolled back and
     *    reported on the field, so the user keeps the working, verified address instead
     *    of being left on an unverified one with no way to prove it.
     */
    public function update(ProfileUpdateRequest $request, MailManager $mail): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());

        if (! $user->isDirty('email') || ! $mail->canDeliver()) {
            $user->save();

            return to_route('profile.edit');
        }

        DB::transaction(function () use ($user) {
            $user->email_verified_at = null;
            $user->save();

            try {
                $user->sendEmailVerificationNotification();
            } catch (Throwable $e) {
                report($e);

                throw ValidationException::withMessages([
                    'email' => 'Die Bestätigungs-E-Mail konnte nicht zugestellt werden — die Adresse wurde nicht geändert.',
                ]);
            }
        });

        return to_route('profile.edit')->with('status', 'verification-link-sent');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', new CurrentPassword],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
