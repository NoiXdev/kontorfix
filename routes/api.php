<?php

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
    });
