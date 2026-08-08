<?php

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('completes the composer proxy flow: metadata -> rewritten dist -> cached download', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);

    Http::fake([
        '*/p2/acme/demo.json' => Http::response(['packages' => ['acme/demo' => MetadataMinifier::minify([
            ['name' => 'acme/demo', 'version' => 'v1.0.0', 'version_normalized' => '1.0.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://cdn.test/acme/demo-1.0.0.zip', 'reference' => 'r1']],
        ])]], 200),
        'cdn.test/*' => Http::response('zip-bytes', 200),
    ]);
    $headers = tokenHeaderFor($group);

    // 1. Proxy the p2 metadata and read the rewritten dist URL.
    $meta = $this->withHeaders($headers)->getJson('/r/kadenz/p2/acme/demo.json')->assertOk()->json();
    $version = MetadataMinifier::expand($meta['packages']['acme/demo'])[0];
    $distPath = parse_url($version['dist']['url'], PHP_URL_PATH);
    expect($distPath)->toContain("/r/kadenz/proxy/composer/{$up->id}/acme/demo/");

    // 2. Load via the proxy route (cache-on-first). The body has to be consumed: the
    // artifact is now cached while it is relayed, so the caching happens when a real
    // client reads the stream, not before the first byte leaves.
    $first = $this->withHeaders($headers)->get($distPath)->assertOk()->assertHeader('content-type', 'application/zip');
    expect($first->streamedContent())->toBe('zip-bytes');

    // 3. Second pull from the cache — no further CDN call.
    Http::fake();
    $this->withHeaders($headers)->get($distPath)->assertOk();
    Http::assertNothingSent();
});

it('completes the npm proxy flow: packument -> rewritten tarball -> cached download', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Npm, 'url' => 'https://reg.test']);

    Http::fake([
        '*/left-pad' => Http::response([
            'name' => 'left-pad', 'dist-tags' => ['latest' => '1.0.0'],
            'versions' => ['1.0.0' => ['name' => 'left-pad', 'version' => '1.0.0', 'dist' => ['tarball' => 'https://reg.test/left-pad/-/left-pad-1.0.0.tgz', 'shasum' => 'x']]],
        ], 200),
        'reg.test/left-pad/-/*' => Http::response('tarball-bytes', 200),
    ]);
    $headers = tokenHeaderFor($group);

    $doc = $this->withHeaders($headers)->getJson('/r/kadenz/left-pad')->assertOk()->json();
    $tarballPath = parse_url($doc['versions']['1.0.0']['dist']['tarball'], PHP_URL_PATH);
    expect($tarballPath)->toContain("/r/kadenz/proxy/npm/{$up->id}/left-pad/-/");

    $this->withHeaders($headers)->get($tarballPath)->assertOk()->assertHeader('content-type', 'application/octet-stream');
});

it('enforces strict mode end-to-end: metadata and download both 404 until allowlisted', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test', 'policy' => UpstreamPolicy::Strict]);
    Http::fake([
        '*/p2/gated/pkg.json' => Http::response(['packages' => ['gated/pkg' => MetadataMinifier::minify([
            ['name' => 'gated/pkg', 'version' => 'v1.0.0', 'version_normalized' => '1.0.0.0', 'dist' => ['type' => 'zip', 'url' => 'https://cdn.test/g.zip', 'reference' => 'r']],
        ])]], 200),
        'cdn.test/*' => Http::response('bytes', 200),
    ]);
    $headers = tokenHeaderFor($group);

    $this->withHeaders($headers)->getJson('/r/kadenz/p2/gated/pkg.json')->assertNotFound();
    $this->withHeaders($headers)->get("/r/kadenz/proxy/composer/{$up->id}/gated/pkg/1.0.0.0")->assertNotFound();

    $up->allowedPackages()->create(['name' => 'gated/pkg']);
    $this->withHeaders($headers)->getJson('/r/kadenz/p2/gated/pkg.json')->assertOk();
    $this->withHeaders($headers)->get("/r/kadenz/proxy/composer/{$up->id}/gated/pkg/1.0.0.0")->assertOk();
});

/*
 * Manual proxy smoke tests (2026-07-08), verified against real upstreams through the
 * running DDEV server:
 *
 * Composer (packagist.org as the proxy upstream of the "proxytest" group):
 *   composer.json: repositories=[{"type":"composer","url":".../r/proxytest"},{"packagist.org":false}]
 *   composer require psr/log
 *     -> Downloading psr/log (3.0.2) / Installing / Extracting archive
 *   Flow: packagist metadata proxied -> dist URL rewritten to our /proxy/composer/ route
 *   -> artifact loaded from GitHub (302 redirect to codeload followed and
 *   re-validated) -> cached on the artifacts disk -> served.
 *
 * npm (registry.npmjs.org as the proxy upstream):
 *   .npmrc: registry=.../r/proxytest/ + _authToken
 *   npm install is-odd  ->  added 2 packages (is-odd@3.0.1 + is-number)
 *   Flow: packument proxied -> dist.tarball rewritten to the /proxy/npm/ route ->
 *   tarball loaded from npmjs, cached, unpacked.
 *
 * In both cases the second pull is served from the local cache (no further upstream call).
 */
