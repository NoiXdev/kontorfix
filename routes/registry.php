<?php

use App\Http\Controllers\Registry\ComposerController;
use App\Http\Controllers\Registry\LegacySlugRedirectController;
use App\Http\Controllers\Registry\NpmController;
use App\Http\Controllers\Registry\Oci\BlobController;
use App\Http\Controllers\Registry\Oci\ManifestController;
use App\Http\Controllers\Registry\Oci\VersionController;
use App\Http\Controllers\Registry\ProxyDownloadController;
use App\Http\Controllers\Registry\PypiController;
use Illuminate\Support\Facades\Route;

// Registry endpoints are defined ONCE and registered under two access paths:
// via slug prefix (/r/{orgSlug}/{groupSlug}/...) and at the host root for custom domains.
// Group resolution is handled by `registry.context` (see ResolveRegistryContext) —
// controllers read the group from the request attributes.
//
// The OCI distribution API is the one exception, and it is registered separately below:
// its two addressing modes cannot be told apart by the router, so it resolves its group
// through `oci.context` (see ResolveOciContext) instead.

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

// OCI distribution (`/v2/…`), registered at TOP level rather than inside either access
// group below. Both addressing modes a registry has — a hostname of its own, and a path
// namespace on the instance's own host (`/v2/{org}/{registry}/{repo}`) — are decided from
// the Host by `oci.context`, because the router cannot tell them apart: a path-mode URL is
// a valid domain-mode URL whose `{name}` happens to contain slashes, so two route groups
// would leave whichever registered first matching on every host and the second unreachable.
// See ResolveOciContext for the full statement.
//
// Registered BEFORE $registryEndpoints() runs on either access path, not after — the same
// reason npm's bare packument route is registered last within it and PyPI before it:
// Laravel resolves routes in registration order, and npm's `/{package}` catch-all
// (`(?!packages\.json$)[a-z0-9._-]+`) is unconstrained enough to match "v2" as a package
// name. Registered after the domain-access group, this route was dead code — npm's
// packument controller swallowed every /v2/* request first, 401/404ing with its own body
// instead of ever reaching VersionController. Verified by matching the request against the
// route collection directly, not merely by reading the file.
//
// `registry.type:docker` stays here, AFTER `oci.context` in the same list: it reads the
// `registryGroup` that resolver sets and silently no-ops without one, so running it first
// would stop a disabled Docker type from being enforced at all.
Route::middleware(['oci.context', 'registry.auth', 'registry.type:docker'])->prefix('/v2')->group(function () use ($uuid, $ociName, $ociReference) {
    Route::get('/', VersionController::class);

    // Blob upload protocol (Task 4). uploadId is a plain OciBlobUpload UUID, not the
    // OCI name/reference grammar above, so it gets its own constraint — the file's own
    // canonical `$uuid` pattern, not a same-length-but-looser stand-in: `[0-9a-f-]{36}`
    // is satisfied by 36 hyphens as much as by a real UUID, reaches
    // OciBlobUpload::where('id', $uploadId) unconstrained, and lands on a Postgres
    // `uuid` comparison — SQLSTATE[22P02], an unrendered QueryException, a 500 with a
    // stack trace. Precisely the failure this file's own header comment gives as the
    // reason `$uuid` exists in the first place.
    Route::post('/{name}/blobs/uploads/', [BlobController::class, 'begin'])->where('name', $ociName);
    Route::patch('/{name}/blobs/uploads/{uploadId}', [BlobController::class, 'append'])
        ->where(['name' => $ociName, 'uploadId' => $uuid]);
    Route::put('/{name}/blobs/uploads/{uploadId}', [BlobController::class, 'finish'])
        ->where(['name' => $ociName, 'uploadId' => $uuid]);
    Route::match(['GET', 'HEAD'], '/{name}/blobs/{digest}', [BlobController::class, 'show'])
        ->where(['name' => $ociName, 'digest' => 'sha256:[a-f0-9]{64}']);

    // Manifests and tags (Task 5). {reference} is a tag or a digest, matched by
    // $ociReference; DELETE is digest-only per the OCI spec, so it gets its own
    // narrower constraint rather than reusing $ociReference.
    Route::match(['GET', 'HEAD'], '/{name}/manifests/{reference}', [ManifestController::class, 'show'])
        ->where(['name' => $ociName, 'reference' => $ociReference]);
    Route::put('/{name}/manifests/{reference}', [ManifestController::class, 'put'])
        ->where(['name' => $ociName, 'reference' => $ociReference]);
    Route::delete('/{name}/manifests/{digest}', [ManifestController::class, 'destroy'])
        ->where(['name' => $ociName, 'digest' => 'sha256:[a-f0-9]{64}']);
    Route::get('/{name}/tags/list', [ManifestController::class, 'tags'])->where('name', $ociName);

    // Anything else under /v2 (an uppercase name, a path with the wrong number of
    // segments, …) is a ROUTING miss — none of the patterns above matched at all — not
    // a well-formed-but-absent name a controller ever got to evaluate. That is exactly
    // the same distinction ResolvesOciRepository draws between a malformed name (plain
    // 404, no body) and one that is merely unregistered (OciException::nameUnknown(),
    // a genuine `errors[]` envelope): inventing a name to run NAME_UNKNOWN's lookup
    // against here would misreport "this input never named a real resource" as "this
    // resource does not exist". Registered as a Route::fallback() rather than left to
    // Laravel's default unmatched-route handling purely so the response body is valid
    // JSON — an HTML error page fails `TestResponse::decodeResponseJson()`, which
    // rethrows the original routing exception instead of asserting on the body.
    //
    // GET-only (Route::fallback()'s own default), registered inside this specific
    // `->prefix('/v2')` group — so it never reaches past /v2, and does not apply to any
    // other HTTP verb. A wrong-method request against an otherwise-valid /v2 path (e.g.
    // POST to a manifest URL) does NOT fall through to this fallback — an earlier
    // version of this comment claimed it answers 404, which does not hold: Laravel's
    // router resolves the URI against every registered method before it ever considers
    // a fallback route (RouteCollection::checkForAlternateVerbs()), so a method
    // mismatch against a path some route DOES recognise throws
    // MethodNotAllowedHttpException — a 405 with an `Allow` header — well before this
    // fallback is reached. Verified directly (not merely reasoned about) and pinned by
    // OciRoutingTest's "answers a method mismatch under /v2 with 405" case. This
    // fallback only ever fires for a genuine routing MISS: no registered route, under
    // any method, recognises the path at all.
    Route::fallback(fn () => response()->json((object) [], 404));
});

// Slug access: the organization scopes the registry slug, so both segments are needed to
// identify one registry. Resolved by the middleware; see ResolveRegistryContext.
Route::prefix('/r/{orgSlug}/{groupSlug}')
    ->where(['orgSlug' => '[a-z0-9-]+', 'groupSlug' => '[a-z0-9-]+'])
    ->middleware(['registry.context', 'registry.auth'])
    ->group($registryEndpoints);

// Domain access: root level. registry.context 404s unknown hosts, so these routes
// don't shadow the main app (web routes are registered first -> first match). The OCI
// routes above deliberately do NOT sit in here any more: they answer on this instance's
// own host too, where there is no `domains` row to resolve.
Route::middleware(['registry.context', 'registry.auth'])->group($registryEndpoints);

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
