<?php

// The per-artifact cap was applied to a string that had already been materialised in full:
// `UpstreamClient::getBytes()` returned `$response->body()`, so on the shipped 128 M
// memory_limit a 100 MiB cap could never be reached — an oversize artifact exhausted the
// worker rather than being declined, and an anonymous caller against a public registry
// chooses the artifact. The cap now applies to the bytes as they arrive.

use App\Enums\PackageType;
use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Upstream;
use App\Models\UpstreamMetadataCache;
use App\Services\Upstream\UpstreamCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FixtureRepo;

function seedProxyDist(Upstream $up, string $name, string $version, string $url): void
{
    UpstreamMetadataCache::create([
        'upstream_id' => $up->id,
        'package_name' => $name,
        'payload' => ['packages' => [$name => [[
            'name' => $name, 'version' => "v{$version}", 'version_normalized' => $version,
            'dist' => ['type' => 'zip', 'url' => $url, 'reference' => 'abc'],
        ]]]],
        'fetched_at' => now(),
    ]);
}

it('holds only a chunk in memory while relaying an artifact far larger than the cap', function () {
    Storage::fake('artifacts');
    config(['kontorfix.upstream_cache_max_artifact_bytes' => 1024]);

    // 12 MiB, roughly 12 000x the cap. php://temp spills to disk past 2 MiB, so the
    // source is not itself a 12 MiB allocation.
    $source = fopen('php://temp', 'w+b');
    foreach (range(1, 12) as $i) {
        fwrite($source, str_repeat('A', 1024 * 1024));
    }
    rewind($source);

    // Discard the relayed output a megabyte at a time, so the assertion measures the
    // relay and not the test's own buffer.
    ob_start(fn () => '', 1024 * 1024);
    memory_reset_peak_usage();
    $base = memory_get_peak_usage(true);

    $bytes = app(UpstreamCache::class)->relayArtifact($source, 'proxy/x/big.zip');

    $peak = memory_get_peak_usage(true) - $base;
    ob_end_clean();

    expect($bytes)->toBe(12 * 1024 * 1024)
        ->and($peak)->toBeLessThan(4 * 1024 * 1024);

    // Over the cap, so nothing was cached — but every byte was delivered.
    expect(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('serves an oversize artifact in full over http while declining to cache it', function () {
    Storage::fake('artifacts');
    config(['kontorfix.upstream_cache_max_artifact_bytes' => 8]);

    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedProxyDist($up, 'acme/demo', '1.0.0.0', 'https://cdn.test/acme/demo-1.0.0.zip');
    // Deliberately larger than the relay's read granularity, so "delivered in full" is a
    // claim about every chunk after the cap was passed, not only about the first one.
    Http::fake(['cdn.test/*' => Http::response(str_repeat('Z', 1024 * 1024), 200)]);

    $response = $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")
        ->assertOk();

    // Declining to cache must never turn into "this package cannot be installed".
    expect(strlen($response->streamedContent()))->toBe(1024 * 1024)
        ->and(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('caches an artifact that fits, on the way past to the client', function () {
    Storage::fake('artifacts');
    config(['kontorfix.upstream_cache_max_artifact_bytes' => 1048576]);

    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedProxyDist($up, 'acme/demo', '1.0.0.0', 'https://cdn.test/acme/demo-1.0.0.zip');
    Http::fake(['cdn.test/*' => Http::response('zip-bytes', 200)]);

    $response = $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")->assertOk();

    expect($response->streamedContent())->toBe('zip-bytes');

    Storage::disk('artifacts')->assertExists("proxy/{$up->id}/composer/acme/demo/1.0.0.0.zip");
    expect(Storage::disk('artifacts')->allFiles())
        ->toBe(["proxy/{$up->id}/composer/acme/demo/1.0.0.0.zip"]);
});

// The Composer dist build is the other unbounded amplifier on the registry surface: the
// first request for a version clones the repository and zips it. Without a lock, N
// concurrent requests for the same cold version each do that.

it('still builds the dist when another request holds the lock and never lets go', function () {
    Storage::fake('artifacts');
    // A stuck holder must not become a stuck download; the wait is bounded and the
    // fallback is the unlocked build that predates the lock.
    config(['kontorfix.dist_build_lock_wait' => 0]);

    $package = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($package))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($package);

    $sha = $package->versions()->where('version', '1.0.0.0')->sole()->source_reference;
    $path = "dists/{$package->id}/{$sha}.zip";

    // Held for the whole request and deliberately never released.
    expect(Cache::lock('dist-build:'.$path, 300)->get())->toBeTrue();

    $this->withHeaders(tokenHeaderFor($group))
        ->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')
        ->assertOk()->assertHeader('content-type', 'application/zip');

    Storage::disk('artifacts')->assertExists($path);
});

it('releases the dist lock after a build so the next cold version is not blocked', function () {
    Storage::fake('artifacts');

    $package = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($package))->handle();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $group->packages()->attach($package);

    $sha = $package->versions()->where('version', '1.0.0.0')->sole()->source_reference;
    $path = "dists/{$package->id}/{$sha}.zip";

    $this->withHeaders(tokenHeaderFor($group))->get('/r/kadenz/dists/acme/demo/1.0.0.0.zip')->assertOk();

    expect(Cache::lock('dist-build:'.$path, 300)->get())->toBeTrue();
});
