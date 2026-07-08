<?php

use App\Models\Upstream;
use App\Services\Upstream\UpstreamCache;
use Illuminate\Support\Facades\Storage;

it('stores and returns cached metadata within ttl, misses after expiry', function () {
    config(['kontorfix.upstream_cache_ttl' => 300]);
    $up = Upstream::factory()->create();
    $cache = app(UpstreamCache::class);

    $cache->putMetadata($up, 'acme/demo', ['v' => 1]);
    expect($cache->getMetadata($up, 'acme/demo'))->toBe(['v' => 1]);

    $up->metadataCache()->where('package_name', 'acme/demo')->update(['fetched_at' => now()->subHour()]);
    expect($cache->getMetadata($up, 'acme/demo'))->toBeNull();
});

it('overwrites cached metadata on re-put (fresh fetch)', function () {
    $up = Upstream::factory()->create();
    $cache = app(UpstreamCache::class);
    $cache->putMetadata($up, 'acme/demo', ['v' => 1]);
    $cache->putMetadata($up, 'acme/demo', ['v' => 2]);

    expect($cache->getMetadata($up, 'acme/demo'))->toBe(['v' => 2])
        ->and($up->metadataCache()->where('package_name', 'acme/demo')->count())->toBe(1);
});

it('caches an artifact on the artifacts disk and reports hits', function () {
    Storage::fake('artifacts');
    $up = Upstream::factory()->create();
    $cache = app(UpstreamCache::class);
    $path = "proxy/{$up->id}/acme/demo-1.0.0.zip";

    expect($cache->hasArtifact($path))->toBeFalse();
    $cache->putArtifact($path, 'bytes');
    expect($cache->hasArtifact($path))->toBeTrue();
    Storage::disk('artifacts')->assertExists($path);
});
