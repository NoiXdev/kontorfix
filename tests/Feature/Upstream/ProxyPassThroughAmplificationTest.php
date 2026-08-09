<?php

// A04 — the byte budget bounds DISK, not WORK. An artifact over the per-artifact cap, or
// one arriving while the budget is spent, was relayed uncached — deliberately, so a storage
// policy never becomes an install failure — but `hasArtifact()` then stayed false forever,
// so every later request for the same coordinate was another full upstream fetch and another
// full relay. With no request budget in front of the registry routes (by design: one
// `composer install` fires hundreds of metadata requests), and with the prune only ageing
// artifacts out after 30 days, a full cache put the whole proxy into permanent pass-through.
//
// Two controls, because there are two states:
//  - budget full: the cache reclaims space instead of wedging. The permanent state is gone.
//  - genuinely uncacheable (over the per-artifact cap): a per-artifact fetch lock so that
//    concurrent misses for the same coordinate do not each become their own upstream fetch.
//
// Neither may make a legitimate large download fail — the lock falls through on timeout and
// eviction only ever affects caching, never serving.

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use App\Models\UpstreamMetadataCache;
use App\Services\Upstream\UpstreamCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function seedAmplificationDist(Upstream $up, string $name, string $version, string $url): void
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

it('reclaims space rather than staying in pass-through until the 30-day prune', function () {
    Storage::fake('artifacts');
    config([
        'kontorfix.upstream_cache_max_bytes' => 100,
        'kontorfix.upstream_cache_max_artifact_bytes' => 0,
    ]);
    $up = Upstream::factory()->create();
    $cache = app(UpstreamCache::class);

    // A cache sitting at its budget, filled a while ago — the state the prune would only
    // clear after `upstream_cache_prune_days`.
    expect($cache->putArtifact("proxy/{$up->id}/cold.zip", str_repeat('A', 90)))->toBeTrue();
    touch(Storage::disk('artifacts')->path("proxy/{$up->id}/cold.zip"), now()->subDay()->getTimestamp());
    Cache::forget('upstream-cache:proxy-bytes');

    // The next artifact is cached — the budget is a size limit, not a one-way wall.
    expect($cache->putArtifact("proxy/{$up->id}/warm.zip", str_repeat('B', 90)))->toBeTrue();

    Storage::disk('artifacts')->assertExists("proxy/{$up->id}/warm.zip");
    Storage::disk('artifacts')->assertMissing("proxy/{$up->id}/cold.zip");
});

it('never evicts an artifact another request may still be writing or reading', function () {
    Storage::fake('artifacts');
    config([
        'kontorfix.upstream_cache_max_bytes' => 100,
        'kontorfix.upstream_cache_max_artifact_bytes' => 0,
    ]);
    $up = Upstream::factory()->create();
    $cache = app(UpstreamCache::class);

    // Written just now: inside the eviction floor. Without the floor a burst of cold
    // artifacts evicts each other in a loop, which is the amplifier, not the fix.
    expect($cache->putArtifact("proxy/{$up->id}/fresh.zip", str_repeat('A', 90)))->toBeTrue();

    expect($cache->putArtifact("proxy/{$up->id}/next.zip", str_repeat('B', 90)))->toBeFalse();
    Storage::disk('artifacts')->assertExists("proxy/{$up->id}/fresh.zip");
});

it('will not evict the whole cache for one artifact that could never fit anyway', function () {
    Storage::fake('artifacts');
    config([
        'kontorfix.upstream_cache_max_bytes' => 100,
        'kontorfix.upstream_cache_max_artifact_bytes' => 0,
    ]);
    $up = Upstream::factory()->create();
    $cache = app(UpstreamCache::class);

    $cache->putArtifact("proxy/{$up->id}/kept.zip", str_repeat('A', 50));
    touch(Storage::disk('artifacts')->path("proxy/{$up->id}/kept.zip"), now()->subDay()->getTimestamp());
    Cache::forget('upstream-cache:proxy-bytes');

    expect($cache->putArtifact("proxy/{$up->id}/huge.zip", str_repeat('B', 500)))->toBeFalse();
    Storage::disk('artifacts')->assertExists("proxy/{$up->id}/kept.zip");
});

it('re-checks the cache after taking the fetch lock instead of fetching upstream again', function () {
    Storage::fake('artifacts');

    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedAmplificationDist($up, 'acme/demo', '1.0.0.0', 'https://cdn.test/acme/demo-1.0.0.zip');
    Http::fake(['cdn.test/*' => Http::response('from-upstream', 200)]);

    $path = "proxy/{$up->id}/composer/acme/demo/1.0.0.0.zip";
    Storage::disk('artifacts')->put($path, 'from-disk');

    // The holder that filled the cache while this request was queued behind the lock: the
    // first look misses, the look taken after the lock is acquired hits.
    $cache = new class extends UpstreamCache
    {
        public int $looks = 0;

        public function hasArtifact(string $path): bool
        {
            return ++$this->looks > 1;
        }
    };
    app()->instance(UpstreamCache::class, $cache);

    $response = $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")
        ->assertOk();

    expect($response->streamedContent())->toBe('from-disk')
        ->and($cache->looks)->toBeGreaterThan(1);
    // The whole point: the duplicate upstream fetch never happened.
    Http::assertNothingSent();
});

it('still fetches upstream when nothing filled the cache — the anchor for the case above', function () {
    Storage::fake('artifacts');

    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedAmplificationDist($up, 'acme/demo', '1.0.0.0', 'https://cdn.test/acme/demo-1.0.0.zip');
    Http::fake(['cdn.test/*' => Http::response('from-upstream', 200)]);

    $response = $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")
        ->assertOk();

    // Same route, same token, same upstream, same metadata row — so the assertion above
    // is about the post-lock re-check and not about an earlier layer refusing the request.
    expect($response->streamedContent())->toBe('from-upstream');
    Http::assertSentCount(1);
});

it('serves the artifact anyway when the fetch lock cannot be had', function () {
    Storage::fake('artifacts');
    config(['kontorfix.upstream_fetch_lock_wait' => 0]);

    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedAmplificationDist($up, 'acme/demo', '1.0.0.0', 'https://cdn.test/acme/demo-1.0.0.zip');
    Http::fake(['cdn.test/*' => Http::response('zip-bytes', 200)]);

    // Another worker is mid-relay of the same coordinate and holds the lock.
    $path = "proxy/{$up->id}/composer/acme/demo/1.0.0.0.zip";
    Cache::lock('upstream-fetch:'.$path, 300)->get();

    $response = $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")
        ->assertOk();

    // Waiting is bounded and never refuses the download: a legitimate large artifact under
    // contention is slower, never a 4xx/5xx.
    expect($response->streamedContent())->toBe('zip-bytes');
});
