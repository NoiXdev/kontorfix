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

// The German copy the org-level-registry spec pins verbatim for a write attempted through
// the `/o/{orgSlug}` listing prefix. That prefix lists every registry the organization
// owns rather than addressing one, so a publish there could never say which registry
// inside the organization it targets — hence a flat refusal rather than routing it through
// to a controller that would have to guess.
$orgWriteDenied = fn () => abort(405, 'Veröffentlichen ist nur je Registry möglich — nutzen Sie die Registry-eigene Adresse.');

// Write routes (npm publish/publishScoped, pypi upload) are the only ones this closure
// registers differently per mount: the two existing mounts (`/r/{org}/{group}` and the
// custom-domain root) address one registry unambiguously, so they keep the real controller
// action; the `/o/{orgSlug}` mount added below reuses this SAME closure but must answer
// every one of those three routes with the 405 above instead.
//
// $registryEndpoints is therefore a factory that returns the actual route-registering
// closure with `$readOnly` bound into it via `use`, rather than a single closure that takes
// `$readOnly` as its own parameter: Route::group()'s callback is invoked by
// Illuminate\Routing\Router::loadRoutes() as `$callback($this)`, with the router itself as
// the argument — there is no way for a caller of ->group() to pass a boolean through that
// parameter, so it has to be captured by closure instead. The alternative the plan floated
// (register the write routes a second time, after the fact, to override them) was rejected:
// it would depend on undocumented "last registration for this method+URI wins" router
// behaviour instead of a single, obvious source of truth for what each mount does.
//
// $namePrefix (also factory-bound, same reason) opts a mount into per-route names. It
// defaults to null so the two existing mounts stay exactly as unnamed as they always were.
// The `/o/{orgSlug}` mount below passes 'registry.org.' — every route it registers gets an
// explicit, distinct name via the $named() helper. This is NOT cosmetic: an earlier version
// of this mount instead called ->name('registry.org.') on the *group* wrapping
// ->group($registryEndpoints(true)) while leaving every individual route unnamed. Laravel's
// group-name merging assigns an unnamed route's action['as'] directly from the enclosing
// group's 'as' attribute — it does not require a route to opt in by calling ->name() itself
// — so every one of the ~16 routes in this closure silently collapsed onto the single
// literal name "registry.org.", and route('registry.org.') resolved to whichever route
// happened to be registered LAST (verified via `artisan route:list` before this fix; it had
// also already leaked into resources/js/ziggy.d.ts as one bogus entry). Naming each route
// explicitly here, with the prefix baked into the full name up front, sidesteps that
// group-merge behaviour entirely rather than depending on it.
$named = function ($route, ?string $namePrefix, string $suffix) {
    if ($namePrefix !== null) {
        $route->name($namePrefix.$suffix);
    }

    return $route;
};

$registryEndpoints = function (bool $readOnly = false, ?string $namePrefix = null) use ($uuid, $orgWriteDenied, $named) {
    return function () use ($uuid, $readOnly, $orgWriteDenied, $namePrefix, $named) {
        // Composer — gated by the composer type being enabled for the group's organization.
        // No writes at all in this block (composer install is read-only), so $readOnly
        // changes nothing here.
        Route::middleware('registry.type:composer')->group(function () use ($namePrefix, $named) {
            $named(Route::get('/packages.json', [ComposerController::class, 'root']), $namePrefix, 'composer.root');
            $named(
                Route::get('/p2/{vendor}/{name}.json', [ComposerController::class, 'metadata'])
                    ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.~-]+']),
                $namePrefix,
                'composer.metadata',
            );
            // `{version}` is interpolated into a storage key. `[^/]+` was not enough: Flysystem
            // normalises `\` to `/` before collapsing `..`, so a backslash escaped the intended
            // directory. Constrain it to the character set real Composer versions actually use
            // (digits, dots, dashes, underscores, `+` build metadata, `dev-` prefixes) — a `/`
            // was never matchable here anyway, so no version that used to resolve stops doing so.
            $named(
                Route::get('/dists/{vendor}/{name}/{version}.zip', [ComposerController::class, 'dist'])
                    ->where(['vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.-]+', 'version' => '[A-Za-z0-9._+~-]+']),
                $namePrefix,
                'composer.dist',
            );
        });

        // Proxy-download routes (Composer + npm): EXCLUDED under $readOnly, i.e. under the
        // `/o/{orgSlug}` org mount — the same factory parameter the write routes below use to
        // opt themselves out of that mount, reused here for the same reason: this is scope
        // the org mount must never reach at all, rather than a controller decision.
        //
        // ProxyDownloadController::composer()/npm()/npmScoped() all resolve their group via
        // the non-nullable ResolvesRegistryPackage::registryGroup($request); under `/o/` that
        // attribute is never set (registryOrganization is set instead, registryGroup stays
        // null), so reaching any of these three actions there threw an uncaught TypeError -> a
        // 500, reachable anonymously on a fully predictable URL (found live in review, both
        // ecosystems). Fixed by exclusion, not by teaching ProxyDownloadController an org
        // branch: a cached proxy artifact is defined by one group's specific upstream and has
        // no organization-wide analog — there is no single "the upstream" to proxy for an org
        // aggregate spanning several groups, each with its own (or no) upstream. Composer's and
        // npm's own org branches reach the identical conclusion for their non-proxy read paths
        // (see ComposerController::root()'s docblock and NpmController's org branches), and
        // this fix keeps that answer consistent for the proxy paths instead of inventing a
        // second, different one. Excluding the routes here also removes the whole class of "a
        // shared closure route forgot the org context" for this controller going forward,
        // rather than relying on every action inside it to remember to check.
        if (! $readOnly) {
            Route::middleware('registry.type:composer')->group(function () use ($uuid, $namePrefix, $named) {
                $named(
                    Route::get('/proxy/composer/{upstream}/{vendor}/{name}/{version}', [ProxyDownloadController::class, 'composer'])
                        ->where(['upstream' => $uuid, 'vendor' => '[a-z0-9_.-]+', 'name' => '[a-z0-9_.-]+', 'version' => '[A-Za-z0-9._+~-]+']),
                    $namePrefix,
                    'composer.proxy',
                );
            });

            Route::middleware('registry.type:npm')->group(function () use ($uuid, $namePrefix, $named) {
                $named(
                    Route::get('/proxy/npm/{upstream}/{scope}/{package}/-/{file}', [ProxyDownloadController::class, 'npmScoped'])
                        ->where(['upstream' => $uuid, 'scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']),
                    $namePrefix,
                    'npm.proxy-scoped',
                );
                $named(
                    Route::get('/proxy/npm/{upstream}/{package}/-/{file}', [ProxyDownloadController::class, 'npm'])
                        ->where(['upstream' => $uuid, 'package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']),
                    $namePrefix,
                    'npm.proxy',
                );
            });
        }

        // PyPI (Python) — registered before the greedy npm catch-all so `/simple` and
        // `/pypi/...` are not swallowed by the bare packument route. twine uploads land on
        // the registry root via POST — the one WRITE in this block.
        Route::middleware('registry.type:python')->group(function () use ($uuid, $readOnly, $orgWriteDenied, $namePrefix, $named) {
            $named(Route::post('/', $readOnly ? $orgWriteDenied : [PypiController::class, 'upload']), $namePrefix, 'pypi.upload');
            $named(Route::get('/simple', [PypiController::class, 'simpleRoot']), $namePrefix, 'pypi.simple-root');
            $named(
                Route::get('/simple/{project}', [PypiController::class, 'simpleProject'])
                    ->where(['project' => '[A-Za-z0-9._-]+']),
                $namePrefix,
                'pypi.simple-project',
            );
            $named(
                Route::get('/pypi/files/{package}/{filename}', [PypiController::class, 'download'])
                    ->where(['package' => $uuid, 'filename' => '[A-Za-z0-9][A-Za-z0-9._+-]*\.(whl|tar\.gz|zip)']),
                $namePrefix,
                'pypi.download',
            );
        });

        // npm — after the Composer routes (first match protects packages.json/p2/dists).
        // The `/-/` tarball path doesn't collide with any Composer route, so the tarball
        // routes don't need a packages.json lookahead — only the bare packument catch-all below does.
        Route::middleware('registry.type:npm')->group(function () use ($readOnly, $orgWriteDenied, $namePrefix, $named) {
            $named(
                Route::get('/{scope}/{package}/-/{file}', [NpmController::class, 'tarballScoped'])
                    ->where(['scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']),
                $namePrefix,
                'npm.tarball-scoped',
            );
            $named(
                Route::get('/{package}/-/{file}', [NpmController::class, 'tarball'])
                    ->where(['package' => '[a-z0-9._-]+', 'file' => '[a-z0-9._~-]+\.tgz']),
                $namePrefix,
                'npm.tarball',
            );
            $named(
                Route::get('/{scope}/{package}', [NpmController::class, 'packumentScoped'])
                    ->where(['scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+']),
                $namePrefix,
                'npm.packument-scoped',
            );
            $named(
                Route::get('/{package}', [NpmController::class, 'packument'])
                    ->where(['package' => '(?!packages\.json$)[a-z0-9._-]+']),
                $namePrefix,
                'npm.packument',
            );

            // The two WRITE routes in this block: npm publish.
            $named(
                Route::put('/{scope}/{package}', $readOnly ? $orgWriteDenied : [NpmController::class, 'publishScoped'])
                    ->where(['scope' => '@[a-z0-9._-]+', 'package' => '[a-z0-9._-]+']),
                $namePrefix,
                'npm.publish-scoped',
            );
            $named(
                Route::put('/{package}', $readOnly ? $orgWriteDenied : [NpmController::class, 'publish'])
                    ->where(['package' => '[a-z0-9._-]+']),
                $namePrefix,
                'npm.publish',
            );
        });
    };
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
    ->group($registryEndpoints());

// Domain access: root level. registry.context 404s unknown hosts, so these routes
// don't shadow the main app (web routes are registered first -> first match). The OCI
// routes above deliberately do NOT sit in here any more: they answer on this instance's
// own host too, where there is no `domains` row to resolve.
Route::middleware(['registry.context', 'registry.auth'])->group($registryEndpoints());

// Org access: one organization's whole registry catalogue under a single prefix, rather
// than one specific registry. ResolveRegistryContext resolves `{orgSlug}` alone (no
// `{groupSlug}` here, unlike the slug-access mount above) and sets `registryOrganization`
// instead of `registryGroup` — see the middleware for why the group stays null there.
// Read-only: `$registryEndpoints(true, 'registry.org.')` swaps in a 405 for the three write
// routes (npm publish, npm publishScoped, pypi upload) instead of registering their real
// controller actions, because this prefix cannot say which one registry inside the
// organization a publish would target.
//
// The second argument, 'registry.org.', is NOT set via ->name() on this group — see the
// long comment above $registryEndpoints for why that (the seemingly obvious way to do it)
// silently collapses every route in the closure onto one shared name instead of prefixing
// each one individually. Every route this closure registers under this mount gets its own
// full, distinct name instead (e.g. 'registry.org.composer.root',
// 'registry.org.npm.publish'), unlike the two mounts above, which stay unnamed exactly as
// before.
Route::prefix('/o/{orgSlug}')
    ->where(['orgSlug' => '[a-z0-9-]+'])
    ->middleware(['registry.context', 'registry.auth'])
    ->group($registryEndpoints(true, 'registry.org.'));

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
