<?php

use App\Http\Controllers\Settings\AccessTokenController;
use App\Http\Controllers\Settings\ApiKeyController;
use App\Http\Controllers\Settings\PasskeyController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorController;
use App\Http\Middleware\ConfirmPasswordOnEmailChange;
use App\Http\Middleware\ConfirmPasswordUnlessSubmitted;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    // The address on this form is the account's password-reset channel, so moving it
    // re-proves the password like every other route that can outlive the session. The
    // middleware only engages when the address actually changes — see its docblock.
    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->middleware(ConfirmPasswordOnEmailChange::class)
        ->name('profile.update');
    // Deleting your own account proves the password inline. Accounts this application
    // creates without one (OIDC-provisioned, admin-invited, passkey-only) hold a random
    // hash nobody knows, so that was a route they could never take; submitting no password
    // now sends them to the confirmation screen, which accepts a passkey or mails a
    // set-password link. See ConfirmPasswordUnlessSubmitted.
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])
        ->middleware(ConfirmPasswordUnlessSubmitted::class)
        ->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    // Enrolling a second factor decides who can log in from now on, so it is gated by
    // `password.confirm` just like `settings/passkeys` — a stolen session alone must not
    // be enough to enroll an attacker's authenticator and lock the owner out. `show` is
    // included because it hands out the plaintext secret and the recovery codes.
    Route::middleware('password.confirm')->group(function () {
        Route::get('settings/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
        Route::post('settings/two-factor/enable', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
        Route::post('settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
    });

    // `disable` proves the password itself (DisableTwoFactorRequest); the middleware is
    // only the way in for an account whose owner never knew one — otherwise such a user
    // could enable a second factor (a passkey satisfies that gate) and never switch it off.
    Route::delete('settings/two-factor', [TwoFactorController::class, 'disable'])
        ->middleware(ConfirmPasswordUnlessSubmitted::class)
        ->name('two-factor.disable');

    Route::get('settings/passkeys', [PasskeyController::class, 'index'])
        ->middleware('password.confirm')
        ->name('passkeys.show');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');

    // Registry tokens and API keys are long-lived bearer credentials that outlive the
    // session they were minted from, so the whole area re-proves the password like
    // `settings/passkeys` does. Gating the index too (rather than only the POST) is
    // what makes it usable: the prompt happens on the way into the page, so the create
    // form is never submitted into a redirect that would discard it.
    Route::middleware('password.confirm')->group(function () {
        Route::get('settings/tokens', [AccessTokenController::class, 'index'])->name('tokens.index');
        Route::post('settings/tokens', [AccessTokenController::class, 'store'])->name('tokens.store');
        Route::delete('settings/tokens/{token}', [AccessTokenController::class, 'destroy'])->name('tokens.destroy');

        Route::get('settings/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
        Route::post('settings/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
        Route::delete('settings/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
    });
});
