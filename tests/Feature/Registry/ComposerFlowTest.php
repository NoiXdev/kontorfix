<?php

use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Composer\MetadataMinifier\MetadataMinifier;
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
    $root = $this->withHeaders($headers)->getJson('/r/kadenz/packages.json')->assertOk()->json();
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

    $this->getJson('/r/kadenz/packages.json')->assertUnauthorized();
    $this->getJson('/r/kadenz/p2/acme/demo.json')->assertUnauthorized();
    $this->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertUnauthorized();
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
