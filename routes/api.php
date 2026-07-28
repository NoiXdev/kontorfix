<?php

use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

// Alle Management-Endpunkte sind stateless (Bearer-Key), versioniert unter /api/v1.
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['throttle:api', 'api.auth'])
    ->group(function () {
        Route::get('me', [MeController::class, 'show'])->name('me');
    });
