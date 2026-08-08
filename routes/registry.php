<?php

use App\Http\Controllers\Registry\ComposerController;
use App\Http\Controllers\Registry\NpmController;
use App\Http\Controllers\Registry\ProxyDownloadController;
use App\Http\Controllers\Registry\PypiController;
use Illuminate\Support\Facades\Route;

// Registry endpoints are defined ONCE and registered under two access paths:
// via slug prefix (/r/{groupSlug}/...) and at the host root for custom domains.
// Group resolution is handled exclusively by `registry.context` (see
// ResolveRegistryContext) — controllers read the group from the request attributes.

// Canonical UUID shape for the registry's own id parameters. These routes resolve
// their ids with raw find()/whereKey() instead of route-model binding — deliberately,
// so the group-ownership check stays explicit in the controller — and therefore get no
// ModelNotFoundException from the binder when the value is not a UUID. Anything that
// reaches Postgres' `uuid` comparison malformed raises SQLSTATE[22P02], which nothing
// renders, so it becomes a 500 plus a stack trace in the log for every request.
// `[0-9a-fA-F-]{36}` was not enough: 36 dashes satisfy it, as does any other mix of hex
// digits and dashes of that length.
//
// No `throttle:` on this group, deliberately. The flooding these ids enabled was the
// stack trace each 500 appended to an unrotated laravel.log, and a 404 from the router
// writes nothing; a request budget here would instead break the legitimate traffic
// pattern, since one `composer install` or `npm ci` fires hundreds of metadata requests
// from a single address. The DoS surface that is worth bounding — the artifact cache —
// has its own byte budget (KONTORFIX_UPSTREAM_CACHE_MAX_BYTES).
$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

$registryEndpoints = function () use ($uuid) {
    // Composer — gated by the composer type being enabled for the group's organization.
    Route::middleware('registry.type:composer')->group(function () use ($uuid) {
        Route::get('/packages.json', [ComposerController::class, 'root']);
        Route::get('/p2/{vendor}/{name}.json', [ComposerController::class, 'metadata'])
            ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.~-]+']);
        // `{version}` is interpolated into a storage key. `[^/]+` was not enough: Flysystem
        // normalises `\` to `/` before collapsing `..`, so a backslash escaped the intended
        // directory. Constrain it to the character set real Composer versions actually use
        // (digits, dots, dashes, underscores, `+` build metadata, `dev-` prefixes) — a `/`
        // was never matchable here anyway, so no version that used to resolve stops doing so.
        Route::get('/dists/{vendor}/{name}/{version}.zip', [ComposerController::class, 'dist'])
            ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.-]+', 'version' => '[A-Za-z0-9._+~-]+']);

        // Proxy downloads: {upstream} is a UUID, deliberately NOT resolved via route model
        // binding but manually in the controller — this keeps the group-ownership check
        // explicit (no token may trigger downloads via a foreign upstream). The UUID shape
        // is pinned so a non-UUID cannot reach the Postgres uuid comparison and 500.
        Route::get('/proxy/composer/{upstream}/{vendor}/{name}/{version}', [ProxyDownloadController::class, 'composer'])
            ->where(['upstream' => $uuid, 'vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.-]+', 'version' => '[A-Za-z0-9._+~-]+']);
    });

    Route::middleware('registry.type:npm')->group(function () use ($uuid) {
        Route::get('/proxy/npm/{upstream}/{scope}/{package}/-/{file}', [ProxyDownloadController::class, 'npmScoped'])
            ->where(['upstream' => $uuid, 'scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']);
        Route::get('/proxy/npm/{upstream}/{package}/-/{file}', [ProxyDownloadController::class, 'npm'])
            ->where(['upstream' => $uuid, 'package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']);
    });

    // PyPI (Python) — registered before the greedy npm catch-all so `/simple` and
    // `/pypi/...` are not swallowed by the bare packument route. twine uploads land on
    // the registry root via POST.
    Route::middleware('registry.type:python')->group(function () use ($uuid) {
        Route::post('/', [PypiController::class, 'upload']);
        Route::get('/simple', [PypiController::class, 'simpleRoot']);
        Route::get('/simple/{project}', [PypiController::class, 'simpleProject'])
            ->where(['project' => '[A-Za-z0-9._-]+']);
        Route::get('/pypi/files/{package}/{filename}', [PypiController::class, 'download'])
            ->where(['package' => $uuid, 'filename' => '[A-Za-z0-9][A-Za-z0-9._+-]*\.(whl|tar\.gz|zip)']);
    });

    // npm — after the Composer routes (first match protects packages.json/p2/dists).
    // The `/-/` tarball path doesn't collide with any Composer route, so the tarball
    // routes don't need a packages.json lookahead — only the bare packument catch-all below does.
    Route::middleware('registry.type:npm')->group(function () {
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
};

// Slug access: {groupSlug} as a plain parameter, resolved by the middleware.
Route::prefix('/r/{groupSlug}')
    ->where(['groupSlug' => '[a-z0-9-]+'])
    ->middleware(['registry.context', 'registry.auth'])
    ->group($registryEndpoints);

// Domain access: root level. registry.context 404s unknown hosts, so these routes
// don't shadow the main app (web routes are registered first -> first match).
Route::middleware(['registry.context', 'registry.auth'])->group($registryEndpoints);
