<?php

use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FixtureRepo;

it('completes the full composer client flow: root -> p2 -> dist', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group->packages()->attach($pkg);
    $headers = tokenHeaderFor($group);

    // 1. Like `composer update`: fetch the root document.
    $root = $this->withHeaders($headers)->getJson(registryPath($group).'/packages.json')->assertOk()->json();
    expect($root['available-packages'])->toContain('acme/demo');

    // 2. Resolve metadata via the metadata-url template.
    $metaUrl = str_replace('%package%', 'acme/demo', $root['metadata-url']);
    $meta = $this->withHeaders($headers)->getJson($metaUrl)->assertOk()->json();
    $version = MetadataMinifier::expand($meta['packages']['acme/demo'])[0];

    // 3. Download the dist via the exact URL from the metadata.
    $distPath = parse_url($version['dist']['url'], PHP_URL_PATH);
    $this->withHeaders($headers)->get($distPath)
        ->assertOk()
        ->assertHeader('content-type', 'application/zip');
});

it('serves a client that lacks a token nothing but a 401 challenge across the flow', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group->packages()->attach($pkg);

    $this->getJson(registryPath($group).'/packages.json')->assertUnauthorized();
    $this->getJson(registryPath($group).'/p2/acme/demo.json')->assertUnauthorized();
    $this->get(registryPath($group).'/dists/acme/demo/1.0.0.0.zip')->assertUnauthorized();
});

it('answers 404, not every version, when the assignment is revoked between resolve and bounds check', function () {
    // The race VersionEntitlement::boundsFor()'s docblock names: AssignmentWriter::revoke()
    // detaches with no transaction, so a revoke landing between ComposerController::metadata()
    // resolving the package (findLocal(), through Group::assignedPackages(), which DOES
    // filter on `available_until` being null/in the future) and its own separate boundsFor()
    // query (Group::packages(), which deliberately does NOT restate that predicate — see
    // boundsFor()'s own docblock) can make that second, later query find no pivot row at
    // all.
    //
    // Reproduced with a real, unmocked request-response cycle by hooking
    // Connection::beforeExecuting(): the ONLY `group_package` query on this path with no
    // `available_until IS NULL OR ... >` predicate is boundsFor()'s own — every resolution
    // query upstream of it carries that predicate (boundsFor()'s query still SELECTs the
    // `available_until` pivot column, just never filters on it, so matching has to look for
    // the predicate shape, not just the column name). Detaching right there, immediately
    // before that exact query runs, plants the revoke in the precise window between the two
    // reads, deterministically, instead of relying on genuine thread interleaving — and
    // still runs the real production boundsFor() implementation, not a stubbed answer.
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    $group->packages()->attach($pkg);
    $headers = tokenHeaderFor($group);

    // Drift to watch for: this match identifies boundsFor()'s query only as long as it is
    // the sole `group_package` read on this path without the `available_until` predicate —
    // if the RESOLUTION query (findLocal()'s, above) ever lost that predicate, this hook
    // would fire on that query instead, and the test would still pass (a resolution-time
    // detach 404s too) while no longer exercising boundsFor()'s own null branch at all.
    $detached = false;
    DB::beforeExecuting(function (string $query, array $bindings) use (&$detached, $group, $pkg) {
        if (! $detached
            && str_contains($query, 'group_package')
            && ! str_contains($query, 'available_until" is null or')
        ) {
            $detached = true;
            $group->packages()->detach($pkg->getKey());
        }
    });

    // A fail-open bounds answer would serve the metadata document (and every version in
    // it); the fix must answer the same 404 the endpoint already gives for "not accessible
    // to this registry".
    $this->withHeaders($headers)
        ->getJson(registryPath($group).'/p2/acme/demo.json')
        ->assertNotFound();

    expect($detached)->toBeTrue()
        ->and($group->packages()->whereKey($pkg->id)->exists())->toBeFalse();
});

/*
 * Manual smoke test (2026-07-08), verified against the running DDEV server:
 *
 *   composer.json of the throwaway client:
 *     "repositories": [{"type":"composer","url":"https://kontorfix.ddev.site/r/smoke"}]
 *   composer config --auth http-basic.kontorfix.ddev.site token kfx_...
 *   composer update
 *     -> Locking noixdev/smoke (v1.0.0)
 *     -> Downloading / Installing noixdev/smoke (v1.0.0): Extracting archive
 *   Without a token: GET /r/smoke/packages.json -> 401.
 *
 * A real `composer update` resolves the package, downloads the dist zip via the
 * authenticated endpoint, and extracts it — genuine Composer v2 compatibility.
 */
