<?php

use App\Http\Controllers\Registry\ComposerController;
use App\Http\Controllers\Registry\NpmController;
use App\Http\Controllers\Registry\ProxyDownloadController;
use Illuminate\Support\Facades\Route;

// Registry endpoints are defined ONCE and registered under two access paths:
// via slug prefix (/r/{groupSlug}/...) and at the host root for custom domains.
// Group resolution is handled exclusively by `registry.context` (see
// ResolveRegistryContext) — controllers read the group from the request attributes.
$registryEndpoints = function () {
    // Composer
    Route::get('/packages.json', [ComposerController::class, 'root']);
    Route::get('/p2/{vendor}/{name}.json', [ComposerController::class, 'metadata'])
        ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.~-]+']);
    Route::get('/dists/{vendor}/{name}/{version}.zip', [ComposerController::class, 'dist'])
        ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.-]+', 'version' => '[^/]+']);

    // Proxy downloads: {upstream} is a UUID, deliberately NOT resolved via route model
    // binding but manually in the controller — this keeps the group-ownership check
    // explicit (no token may trigger downloads via a foreign upstream).
    Route::get('/proxy/composer/{upstream}/{vendor}/{name}/{version}', [ProxyDownloadController::class, 'composer'])
        ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.-]+', 'version' => '[^/]+']);
    Route::get('/proxy/npm/{upstream}/{scope}/{package}/-/{file}', [ProxyDownloadController::class, 'npmScoped'])
        ->where(['scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']);
    Route::get('/proxy/npm/{upstream}/{package}/-/{file}', [ProxyDownloadController::class, 'npm'])
        ->where(['package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']);

    // npm — after the Composer routes (first match protects packages.json/p2/dists).
    // The `/-/` tarball path doesn't collide with any Composer route, so the tarball
    // routes don't need a packages.json lookahead — only the bare packument catch-all below does.
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
};

// Slug access: {groupSlug} as a plain parameter, resolved by the middleware.
Route::prefix('/r/{groupSlug}')
    ->where(['groupSlug' => '[a-z0-9-]+'])
    ->middleware(['registry.context', 'registry.auth'])
    ->group($registryEndpoints);

// Domain access: root level. registry.context 404s unknown hosts, so these routes
// don't shadow the main app (web routes are registered first -> first match).
Route::middleware(['registry.context', 'registry.auth'])->group($registryEndpoints);
