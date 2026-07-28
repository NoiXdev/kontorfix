<?php

use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

// Alle Management-Endpunkte sind stateless (Bearer-Key), versioniert unter /api/v1.
// Reihenfolge: erst api.auth (setzt das apiKey-Attribut), dann throttle:api — nur so
// kann der Limiter pro Key statt pro IP drosseln (Global Constraint: 120 req/min pro Key).
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['api.auth', 'throttle:api'])
    ->group(function () {
        Route::get('me', [MeController::class, 'show'])->name('me');

        // Self-Service für eigene API-Keys. Feste Segmente (me/api-keys/...) vor
        // etwaigen Wildcard-Routen — keine Kollision mit `me`.
        Route::get('me/api-keys', [ApiKeyController::class, 'index'])->name('me.api-keys.index');
        Route::post('me/api-keys', [ApiKeyController::class, 'store'])->name('me.api-keys.store');
        Route::delete('me/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('me.api-keys.destroy');
    });
