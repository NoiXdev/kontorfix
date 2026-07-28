<?php

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Upstream;
use Illuminate\Support\Facades\Http;

function fakeNpmPackument(string $name, string $version, string $tarball): void
{
    Http::fake(["*/{$name}" => Http::response([
        'name' => $name,
        'dist-tags' => ['latest' => $version],
        'versions' => [$version => [
            'name' => $name, 'version' => $version,
            'dist' => ['tarball' => $tarball, 'shasum' => str_repeat('a', 40), 'integrity' => 'sha512-abc'],
        ]],
    ], 200)]);
}

it('proxies an npm packument and rewrites the tarball url', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Npm, 'url' => 'https://registry.npmjs.org', 'policy' => UpstreamPolicy::Proxy]);
    fakeNpmPackument('left-pad', '1.3.0', 'https://registry.npmjs.org/left-pad/-/left-pad-1.3.0.tgz');

    $doc = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/left-pad')->assertOk()->json();

    expect($doc['name'])->toBe('left-pad')
        ->and($doc['dist-tags']['latest'])->toBe('1.3.0')
        ->and($doc['versions']['1.3.0']['dist']['tarball'])
        ->toBe('http://localhost/r/kadenz/proxy/npm/'.$up->id.'/left-pad/-/left-pad-1.3.0.tgz');
});

it('strict-mode: 404 until allowlisted', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Npm, 'url' => 'https://registry.npmjs.org', 'policy' => UpstreamPolicy::Strict]);
    fakeNpmPackument('evil', '1.0.0', 'https://registry.npmjs.org/evil/-/evil-1.0.0.tgz');

    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/evil')->assertNotFound();
    $up->allowedPackages()->create(['name' => 'evil']);
    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/evil')->assertOk();
});

it('prefers a local npm package and does not call the upstream', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Npm]);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'local-pkg']);
    PackageVersion::factory()->for($pkg)->create(['dist_tarball_name' => 'local-pkg-1.0.0.tgz']);
    $group->packages()->attach($pkg);

    Http::fake();
    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/local-pkg')->assertOk()->assertJsonPath('name', 'local-pkg');
    Http::assertNothingSent();
});

it('does not leak a locally-hosted but inaccessible npm name to the upstream', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Npm]);
    $secret = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'private-pkg']);
    PackageVersion::factory()->for($secret)->create(['dist_tarball_name' => 'private-pkg-1.0.0.tgz']);
    // not assigned

    Http::fake();
    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/private-pkg')->assertNotFound();
    Http::assertNothingSent();
});

it('proxies a scoped npm package', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Npm, 'url' => 'https://registry.npmjs.org']);
    fakeNpmPackument('@babel/core', '7.0.0', 'https://registry.npmjs.org/@babel/core/-/core-7.0.0.tgz');

    $doc = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/@babel/core')->assertOk()->json();
    expect($doc['versions']['7.0.0']['dist']['tarball'])
        ->toBe('http://localhost/r/kadenz/proxy/npm/'.$up->id.'/@babel/core/-/core-7.0.0.tgz');
});

it('returns 502 when the npm upstream errors', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Npm, 'url' => 'https://registry.npmjs.org']);
    Http::fake(['*' => Http::response('boom', 500)]);

    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/whatever')->assertStatus(502);
});
