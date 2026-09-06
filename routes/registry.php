<?php

use App\Http\Controllers\Registry\ComposerController;
use App\Http\Controllers\Registry\LegacySlugRedirectController;
use App\Http\Controllers\Registry\NpmController;
use App\Http\Controllers\Registry\Oci\BlobController;
use App\Http\Controllers\Registry\Oci\VersionController;
use App\Http\Controllers\Registry\ProxyDownloadController;
use App\Http\Controllers\Registry\PypiController;
use Illuminate\Support\Facades\Route;

// Registry endpoints are defined ONCE and registered under two access paths:
// via slug prefix (/r/{orgSlug}/{groupSlug}/...) and at the host root for custom domains.
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
// from a single address.
//
// What this does NOT mean, corrected: KONTORFIX_UPSTREAM_CACHE_MAX_BYTES bounds the disk
// consumed, not the work performed. An artifact over the per-artifact cap is served and
// never cached, so every request for it used to be a fresh upstream fetch and a fresh
// relay, at whatever rate and concurrency the caller chose. The bound for that is on the
// work, not on the requests: ProxyDownloadController::serveArtifact() takes a per-artifact
// fetch lock so concurrent misses collapse into one upstream fetch, and
// UpstreamCache::reclaim() keeps a full budget from turning into a month of pass-through.
$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

// The OCI repository-name grammar. It legitimately contains slashes (`team/app`), so the
// route parameter must accept them rather than treating them as path separators — and it is
// lowercase-only, which is enforced here rather than in a controller so a malformed name is
// a routing miss, not an error body that confirms the registry exists.
$ociName = '[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*(?:/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*';
// A tag or a sha256 digest. Only sha256: we cannot verify what we cannot compute.
$ociReference = '[a-zA-Z0-9_][a-zA-Z0-9._-]{0,127}|sha256:[a-f0-9]{64}';

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

// Slug access: the organization scopes the registry slug, so both segments are needed to
// identify one registry. Resolved by the middleware; see ResolveRegistryContext.
Route::prefix('/r/{orgSlug}/{groupSlug}')
    ->where(['orgSlug' => '[a-z0-9-]+', 'groupSlug' => '[a-z0-9-]+'])
    ->middleware(['registry.context', 'registry.auth'])
    ->group($registryEndpoints);

// Domain access: root level. registry.context 404s unknown hosts, so these routes
// don't shadow the main app (web routes are registered first -> first match).
Route::middleware(['registry.context', 'registry.auth'])->group(function () use ($registryEndpoints, $ociName) {
    // OCI distribution. Registered ONLY here, and that is a protocol constraint rather than
    // a preference: a Docker client will not accept a path prefix as part of a registry
    // address, so it never sends /v2/ to /r/{org}/{registry}. A second registration there
    // would be a second addressing rule that nothing could ever reach.
    //
    // Registered BEFORE $registryEndpoints(), not after — the same reason npm's bare
    // packument route below is registered last within it and PyPI before it: Laravel
    // resolves routes in registration order, and npm's `/{package}` catch-all
    // (`(?!packages\.json$)[a-z0-9._-]+`) is unconstrained enough to match "v2" as a
    // package name. Registered after $registryEndpoints(), this route was dead code —
    // npm's packument controller swallowed every /v2/* request first, 401/404ing with
    // its own body instead of ever reaching VersionController. Verified by matching the
    // request against the route collection directly, not merely by reading the file.
    Route::middleware('registry.type:docker')->prefix('/v2')->group(function () use ($ociName) {
        Route::get('/', VersionController::class);

        // Blob upload protocol (Task 4). uploadId is a plain OciBlobUpload UUID, not the
        // OCI name/reference grammar above, so it gets its own constraint.
        Route::post('/{name}/blobs/uploads/', [BlobController::class, 'begin'])->where('name', $ociName);
        Route::patch('/{name}/blobs/uploads/{uploadId}', [BlobController::class, 'append'])
            ->where(['name' => $ociName, 'uploadId' => '[0-9a-f-]{36}']);
        Route::put('/{name}/blobs/uploads/{uploadId}', [BlobController::class, 'finish'])
            ->where(['name' => $ociName, 'uploadId' => '[0-9a-f-]{36}']);
        Route::match(['GET', 'HEAD'], '/{name}/blobs/{digest}', [BlobController::class, 'show'])
            ->where(['name' => $ociName, 'digest' => 'sha256:[a-f0-9]{64}']);
        // Task 5-6 add the manifest routes here.
    });

    $registryEndpoints();
});

// Legacy slug access, registered last on purpose — see LegacySlugRedirectController.
// GET/HEAD for composer.json/.npmrc/pip.conf reads, PUT for npm publish, POST for twine
// upload — a bare-slug URL that used to 404 for every method should not now 405 a read or
// a write just because some other method on the same shape got a route. 'HEAD' is listed
// explicitly rather than relied on implicitly: \Illuminate\Routing\Route::__construct()
// happens to append HEAD to any method list that already contains GET (verified against
// this app's actual vendored Laravel 13.25, not assumed), so Route::match() would answer
// HEAD correctly even without it here — but LegacySlugRedirector::respond() below still
// needs its own explicit GET-or-HEAD check, since that framework behaviour only affects
// which *route* matches, not which redirect status the controller then picks.
//
// No DELETE here (npm unpublish): the canonical registry endpoints below register no
// DELETE route at all — this app does not implement unpublish yet — so a legacy DELETE
// would have nothing real to redirect to; it would just reach the canonical URL one hop
// later and 404/405 there instead of here. Add it the moment a canonical unpublish route
// exists, not before.
Route::match(['GET', 'HEAD', 'PUT', 'POST'], '/r/{groupSlug}/{rest?}', LegacySlugRedirectController::class)
    ->where(['groupSlug' => '[a-z0-9-]+', 'rest' => '.*']);
