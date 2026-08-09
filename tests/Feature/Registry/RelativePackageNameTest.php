<?php

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use App\Models\UpstreamMetadataCache;
use Illuminate\Support\Facades\Http;

/**
 * The Composer `p2` and npm packument name constraints (`[a-z0-9_.-]+`, `[a-z0-9._~-]+`,
 * `[a-z0-9._-]+`) all admit `.` and `..`. Neither value can name a local package, so the
 * request falls through to the upstream, where the name is interpolated straight into the
 * outbound path — and the response, whatever it is, is written to `upstream_metadata_cache`
 * under that name. The registry routes carry no throttle by design, so the rows are
 * unbounded.
 *
 * `UpstreamCache::isSafeKeySegment()` already refuses exactly this set for the artifact
 * storage key. It never covered these two paths: the guard lives in ProxyDownloadController,
 * and the metadata sinks are a URL path and a database key, not a Flysystem key.
 */
beforeEach(function () {
    $this->group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Http::fake(['*' => Http::response(['minified' => 'composer/2.0', 'packages' => []], 200)]);
});

it('refuses a relative path component in a composer p2 name instead of proxying it upstream', function () {
    Upstream::factory()->for($this->group)->create([
        'type' => PackageType::Composer,
        'url' => 'https://repo.packagist.org',
        'policy' => UpstreamPolicy::Proxy,
    ]);

    // Reachability anchor: an ordinary name on the same route reaches the proxy, is
    // fetched and is cached — so the refusals below are the name guard and not a route
    // miss, the registry context or the token check.
    $this->withHeaders(tokenHeaderFor($this->group))
        ->getJson('/r/kadenz/p2/symfony/console.json')->assertOk();
    expect(UpstreamMetadataCache::count())->toBe(1);
    Http::assertSentCount(1);

    foreach ([['..', '..'], ['symfony', '..'], ['..', 'console'], ['.', 'console']] as [$vendor, $name]) {
        $this->withHeaders(tokenHeaderFor($this->group))
            ->getJson("/r/kadenz/p2/{$vendor}/{$name}.json")
            ->assertNotFound();
    }

    // Nothing left the instance and no row was created beyond the anchor's.
    Http::assertSentCount(1);
    expect(UpstreamMetadataCache::count())->toBe(1);
});

it('refuses a relative path component in an npm packument name instead of proxying it upstream', function () {
    Http::fake(['*' => Http::response(['name' => 'x', 'versions' => []], 200)]);
    Upstream::factory()->for($this->group)->create([
        'type' => PackageType::Npm,
        'url' => 'https://registry.npmjs.org',
        'policy' => UpstreamPolicy::Proxy,
    ]);

    $this->withHeaders(tokenHeaderFor($this->group))
        ->getJson('/r/kadenz/lodash')->assertOk();
    expect(UpstreamMetadataCache::count())->toBe(1);
    Http::assertSentCount(1);

    foreach (['..', '.', '@scope/..', '@scope/.'] as $name) {
        $this->withHeaders(tokenHeaderFor($this->group))
            ->getJson('/r/kadenz/'.$name)
            ->assertNotFound();
    }

    Http::assertSentCount(1);
    expect(UpstreamMetadataCache::count())->toBe(1);
});
