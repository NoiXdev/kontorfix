<?php

use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PackageController;
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

        // Pakete-Verwaltung ist Betreiber-Sache: nur Orgs mit is_operator und
        // Rolle admin/maintainer dürfen anlegen/ändern — dieselben Gates wie die GUI.
        Route::middleware(['operator', 'role:admin,maintainer'])->group(function () {
            Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
            Route::post('packages', [PackageController::class, 'store'])->name('packages.store');
            Route::get('packages/{package}', [PackageController::class, 'show'])->name('packages.show');
            Route::post('packages/{package}/resync', [PackageController::class, 'resync'])->name('packages.resync');
            Route::delete('packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');
        });
    });
