<?php

use App\Http\Controllers\Settings\AccessTokenController;
use App\Http\Controllers\Settings\ApiKeyController;
use App\Http\Controllers\Settings\PasskeyController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::post('settings/two-factor/enable', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
    Route::post('settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
    Route::delete('settings/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');

    Route::get('settings/passkeys', [PasskeyController::class, 'index'])
        ->middleware('password.confirm')
        ->name('passkeys.show');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');

    Route::get('settings/tokens', [AccessTokenController::class, 'index'])->name('tokens.index');
    Route::post('settings/tokens', [AccessTokenController::class, 'store'])->name('tokens.store');
    Route::delete('settings/tokens/{token}', [AccessTokenController::class, 'destroy'])->name('tokens.destroy');

    Route::get('settings/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('settings/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::delete('settings/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
});
