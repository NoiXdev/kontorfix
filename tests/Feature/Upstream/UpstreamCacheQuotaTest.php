<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use App\Models\UpstreamMetadataCache;
use App\Services\Upstream\UpstreamCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * A04 — proxied upstream artifacts were written to the shared artifacts disk with no cap,
 * quota or prune. The attacker freely chooses {vendor}/{name}/{version}, so a loop over an
 * upstream's catalogue fills the operator's volume and takes down every tenant on the
 * instance. Anonymous when the registry group is public, otherwise any read-token holder.
 *
 * The policy under test: the cache is bounded, and hitting the bound degrades to a
 * pass-through fetch — it never refuses to SERVE a package, which would be the outage the
 * quota is supposed to prevent.
 */
it('refuses to cache an artifact larger than the per-artifact cap', function () {
    Storage::fake('artifacts');
    config(['kontorfix.upstream_cache_max_artifact_bytes' => 16]);
    $up = Upstream::factory()->create();

    expect(app(UpstreamCache::class)->putArtifact("proxy/{$up->id}/big.zip", str_repeat('A', 64)))->toBeFalse();
    Storage::disk('artifacts')->assertMissing("proxy/{$up->id}/big.zip");
});

it('stops caching once the total budget is exhausted', function () {
    Storage::fake('artifacts');
    config([
        'kontorfix.upstream_cache_max_bytes' => 100,
        'kontorfix.upstream_cache_max_artifact_bytes' => 0,
    ]);
    $up = Upstream::factory()->create();
    $cache = app(UpstreamCache::class);

    expect($cache->putArtifact("proxy/{$up->id}/a.zip", str_repeat('A', 60)))->toBeTrue();
    expect($cache->putArtifact("proxy/{$up->id}/b.zip", str_repeat('B', 60)))->toBeFalse();

    Storage::disk('artifacts')->assertExists("proxy/{$up->id}/a.zip");
    Storage::disk('artifacts')->assertMissing("proxy/{$up->id}/b.zip");
});

it('caches normally under the default budget', function () {
    Storage::fake('artifacts');
    $up = Upstream::factory()->create();

    expect(app(UpstreamCache::class)->putArtifact("proxy/{$up->id}/a.zip", 'bytes'))->toBeTrue();
    Storage::disk('artifacts')->assertExists("proxy/{$up->id}/a.zip");
});

it('still serves the package when the cache is full — a full cache is not an outage', function () {
    Storage::fake('artifacts');
    config(['kontorfix.upstream_cache_max_bytes' => 1]);

    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    UpstreamMetadataCache::create([
        'upstream_id' => $up->id,
        'package_name' => 'acme/demo',
        'payload' => ['packages' => ['acme/demo' => [[
            'name' => 'acme/demo', 'version' => 'v1.0.0', 'version_normalized' => '1.0.0.0',
            'dist' => ['type' => 'zip', 'url' => 'https://cdn.test/acme/demo-1.0.0.zip', 'reference' => 'abc'],
        ]]]],
        'fetched_at' => now(),
    ]);
    Http::fake(['cdn.test/*' => Http::response('zip-bytes', 200)]);

    $response = $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")
        ->assertOk()->assertHeader('content-type', 'application/zip');

    expect($response->streamedContent())->toBe('zip-bytes');
    // Nothing was written: the budget is exhausted, so the fetch passed straight through.
    expect(Storage::disk('artifacts')->allFiles())->toBe([]);
});

it('prunes proxy artifacts older than the configured age', function () {
    Storage::fake('artifacts');
    $up = Upstream::factory()->create();
    $cache = app(UpstreamCache::class);

    $cache->putArtifact("proxy/{$up->id}/old.zip", 'old');
    $cache->putArtifact("proxy/{$up->id}/new.zip", 'new');
    touch(Storage::disk('artifacts')->path("proxy/{$up->id}/old.zip"), now()->subDays(40)->getTimestamp());

    expect($cache->pruneArtifacts(30))->toBe(1);
    Storage::disk('artifacts')->assertMissing("proxy/{$up->id}/old.zip");
    Storage::disk('artifacts')->assertExists("proxy/{$up->id}/new.zip");
});

it('exposes the prune as a schedulable command', function () {
    Storage::fake('artifacts');
    $up = Upstream::factory()->create();
    app(UpstreamCache::class)->putArtifact("proxy/{$up->id}/old.zip", 'old');
    touch(Storage::disk('artifacts')->path("proxy/{$up->id}/old.zip"), now()->subDays(40)->getTimestamp());

    $this->artisan('upstream-cache:prune --days=30')->assertSuccessful();

    Storage::disk('artifacts')->assertMissing("proxy/{$up->id}/old.zip");
});
