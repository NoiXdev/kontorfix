<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use App\Models\UpstreamMetadataCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function seedComposerCache(Upstream $up, string $package, string $version, string $distUrl): void
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

it('downloads a composer artifact from the upstream, caches it and streams it', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedComposerCache($up, 'acme/demo', '1.0.0.0', 'https://cdn.test/acme/demo-1.0.0.zip');
    Http::fake(['cdn.test/*' => Http::response('zip-bytes', 200)]);

    $res = $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")
        ->assertOk()->assertHeader('content-type', 'application/zip');

    // gecacht auf der Disk
    expect(Storage::disk('artifacts')->allFiles())->not->toBe([]);

    // zweiter Hit: aus dem Cache, kein weiterer Upstream-Call
    Http::fake();
    $this->withHeaders(tokenHeaderFor($group))->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")->assertOk();
    Http::assertNothingSent();
});

it('downloads an npm tarball from the upstream and streams octet-stream', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Npm, 'url' => 'https://reg.test']);
    UpstreamMetadataCache::create([
        'upstream_id' => $up->id, 'package_name' => 'left-pad',
        'payload' => ['name' => 'left-pad', 'versions' => ['1.0.0' => ['dist' => ['tarball' => 'https://reg.test/left-pad/-/left-pad-1.0.0.tgz']]]],
        'fetched_at' => now(),
    ]);
    Http::fake(['reg.test/*' => Http::response('tarball-bytes', 200)]);

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/npm/{$up->id}/left-pad/-/left-pad-1.0.0.tgz")
        ->assertOk()->assertHeader('content-type', 'application/octet-stream');
});

it('401 without token, 404 for an upstream of another group', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $otherUp = Upstream::factory()->create(['type' => PackageType::Composer]); // gehört einer anderen Gruppe
    seedComposerCache($otherUp, 'acme/demo', '1.0.0.0', 'https://cdn.test/x.zip');

    $this->get("/r/kadenz/proxy/composer/{$otherUp->id}/acme/demo/1.0.0.0")->assertUnauthorized();
    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$otherUp->id}/acme/demo/1.0.0.0")->assertNotFound();
});

it('404 when the requested version is not in the cached metadata', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    seedComposerCache($up, 'acme/demo', '1.0.0.0', 'https://cdn.test/a.zip');

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/9.9.9.0")->assertNotFound();
});
