<?php

use App\Http\Controllers\Registry\ComposerController;
use App\Http\Controllers\Registry\NpmController;
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

    // npm — nach den Composer-Routen (First-Match schützt packages.json/p2/dists).
    // Der `/-/`-Tarball-Pfad kollidiert mit keiner Composer-Route, daher brauchen die
    // Tarball-Routen kein packages.json-Lookahead — nur der bare packument-Catch-all unten.
    Route::get('/{scope}/{package}/-/{file}', [NpmController::class, 'tarballScoped'])
        ->where(['scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']);
    Route::get('/{package}/-/{file}', [NpmController::class, 'tarball'])
        ->where(['package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']);
    Route::get('/{scope}/{package}', [NpmController::class, 'packumentScoped'])
        ->where(['scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+']);
    Route::get('/{package}', [NpmController::class, 'packument'])
        ->where(['package' => '(?!packages\.json$)[a-z0-9._-]+']);

    Route::put('/{scope}/{package}', [NpmController::class, 'publishScoped'])
        ->where(['scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+']);
    Route::put('/{package}', [NpmController::class, 'publish'])
        ->where(['package' => '[a-z0-9._-]+']);
});
