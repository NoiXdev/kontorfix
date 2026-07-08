<?php

use App\Http\Controllers\Registry\ComposerController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

// SubstituteBindings ist außerhalb der web/api-Gruppen nicht automatisch aktiv —
// ohne sie würde {group:slug} nie zu einem echten Group-Model aufgelöst.
Route::prefix('/r/{group:slug}')->middleware([SubstituteBindings::class, 'registry.auth'])->group(function () {
    Route::get('/packages.json', [ComposerController::class, 'root']);
    Route::get('/p2/{vendor}/{name}.json', [ComposerController::class, 'metadata'])
        ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.~-]+']);
    Route::get('/dists/{vendor}/{name}/{version}.zip', [ComposerController::class, 'dist'])
        ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.-]+', 'version' => '[^/]+']);
});
