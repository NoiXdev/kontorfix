<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use App\Models\UpstreamMetadataCache;
use App\Services\Upstream\UpstreamCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The proxy artifact key is interpolated from route parameters, and `{version}` was only
 * constrained to exclude `/`. Flysystem's WhitespacePathNormalizer rewrites `\` to `/`
 * BEFORE it collapses `..`, so a backslash sequence escaped the upstream's own cache
 * directory and addressed anything on the `artifacts` disk — in particular
 * `dists/{packageId}/{reference}.zip`, the private Composer source archives that belong
 * to other registry groups.
 *
 * Measured at the vulnerable revision: `Storage::disk('artifacts')->exists()` returned
 * TRUE for the escaped key, and the fetch-and-cache branch WROTE the attacker's bytes to
 * `dists/{uuid}/{ref}.zip`. The read half happened to end in a 500 rather than a leak,
 * because the `Content-Disposition` filename is built from the same `{version}` and
 * Symfony refuses a filename containing `\` — an accident of a header validator, not a
 * control.
 */
function seedProxyMetadata(Upstream $up, string $package, string $version, string $distUrl = 'https://cdn.test/x.zip'): void
{
    UpstreamMetadataCache::create([
        'upstream_id' => $up->id,
        'package_name' => $package,
        'payload' => ['packages' => [$package => [[
            'name' => $package, 'version' => 'v'.$version, 'version_normalized' => $version,
            'dist' => ['type' => 'zip', 'url' => $distUrl, 'reference' => 'abc'],
        ]]]],
        'fetched_at' => now(),
    ]);
}

it('refuses a backslash-traversal version instead of reaching the artifacts disk', function () {
    Storage::fake('artifacts');
    Http::fake();

    // A private dist archive of a registry group the caller holds no token for.
    $victimPackageId = (string) Str::uuid();
    $victimReference = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
    Storage::disk('artifacts')->put("dists/{$victimPackageId}/{$victimReference}.zip", 'VICTIM-PRIVATE-SOURCE');

    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'attacker']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedProxyMetadata($up, 'acme/demo', '1.0.0.0');

    // proxy/{up}/composer/acme/demo/<version>.zip — six `..` reach the disk root.
    $traversal = 'x\\..\\..\\..\\..\\..\\..\\dists\\'.$victimPackageId.'\\'.$victimReference;

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/attacker/proxy/composer/{$up->id}/acme/demo/".rawurlencode($traversal))
        ->assertNotFound();

    Http::assertNothingSent();
});

it('does not let a hostile upstream version pre-seed another registry groups dist path', function () {
    Storage::fake('artifacts');

    $victimPackageId = (string) Str::uuid();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'attacker']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);

    // An upstream under the attacker's control advertises a traversal as the normalised
    // version, so the cache-miss branch writes the fetched bytes wherever it points.
    $traversal = 'x\\..\\..\\..\\..\\..\\..\\dists\\'.$victimPackageId.'\\notyetfetched';
    seedProxyMetadata($up, 'acme/demo', $traversal);
    Http::fake(['cdn.test/*' => Http::response('ATTACKER-CONTROLLED-BYTES', 200)]);

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/attacker/proxy/composer/{$up->id}/acme/demo/".rawurlencode($traversal))
        ->assertNotFound();

    // Nothing at all may land outside the proxy cache prefix.
    expect(Storage::disk('artifacts')->allFiles())->toBe([]);
    Http::assertNothingSent();
});

it('still serves an ordinary composer version through the proxy', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedProxyMetadata($up, 'acme/demo', '1.0.0.0');
    Http::fake(['cdn.test/*' => Http::response('zip-bytes', 200)]);

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")
        ->assertOk();
});

it('accepts the version shapes real ecosystems produce', function () {
    // Composer normalises to four dot-separated numbers plus optional stability suffix or
    // build metadata; refusing any of these would make a legitimate package undownloadable.
    foreach (['1.0.0.0', '2.10.3.0-beta1', '1.0.0.0+build.5', 'dev-main', 'v1_2', '9.9.9.9-RC1'] as $version) {
        expect(UpstreamCache::isSafeKeySegment($version))->toBeTrue("rejected {$version}");
    }
});

it('refuses key segments that can escape the intended directory', function () {
    foreach (['..', '.', 'a/b', 'a\\b', 'a\\..\\b', '..\\..', 'a/../b', '', "a\0b"] as $segment) {
        expect(UpstreamCache::isSafeKeySegment($segment))->toBeFalse('accepted '.json_encode($segment));
    }
});
