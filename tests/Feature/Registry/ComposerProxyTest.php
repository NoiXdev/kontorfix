<?php

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Upstream;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Support\Facades\Http;

function fakePackagistP2(string $package, string $version, string $distUrl): void
{
    Http::fake(["*/p2/{$package}.json" => Http::response([
        'minified' => 'composer/2.0',
        'packages' => [$package => [[
            'name' => $package, 'version' => $version, 'version_normalized' => $version.'.0',
            'dist' => ['type' => 'zip', 'url' => $distUrl, 'reference' => 'abc'],
        ]]],
    ], 200)]);
}

it('proxies composer metadata from the upstream and rewrites dist urls', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.packagist.org', 'policy' => UpstreamPolicy::Proxy]);
    fakePackagistP2('symfony/console', 'v6.0.0', 'https://api.github.com/repos/symfony/console/zipball/abc');

    $res = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/p2/symfony/console.json')->assertOk()->json();
    $v = MetadataMinifier::expand($res['packages']['symfony/console'])[0];

    expect($v['dist']['url'])->toStartWith('http://localhost/r/kadenz/proxy/composer/'.$up->id.'/symfony/console/');
});

it('serves 404 for a proxied package not on the strict allowlist, 200 once allowlisted', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.packagist.org', 'policy' => UpstreamPolicy::Strict]);
    fakePackagistP2('evil/pkg', 'v1.0.0', 'https://x/y.zip');

    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/p2/evil/pkg.json')->assertNotFound();

    $up->allowedPackages()->create(['name' => 'evil/pkg']);
    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/p2/evil/pkg.json')->assertOk();
});

it('returns 404 when the upstream itself has no such package', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.packagist.org']);
    Http::fake(['*' => Http::response('', 404)]);

    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/p2/nope/nope.json')->assertNotFound();
});

it('omits available-packages on the root endpoint when an upstream is active', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Composer]);

    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/packages.json')
        ->assertOk()->assertJsonMissing(['available-packages'])->assertJsonPath('metadata-url', '/r/kadenz/p2/%package%.json');
});

it('still lists available-packages when no upstream is configured', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Composer, 'name' => 'local/pkg']);
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/packages.json')
        ->assertOk()->assertJsonPath('available-packages.0', 'local/pkg');
});

it('prefers a local package over the upstream and does not call it', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Composer]);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Composer, 'name' => 'local/pkg']);
    PackageVersion::factory()->for($pkg)->create();
    $group->packages()->attach($pkg);

    Http::fake();
    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/p2/local/pkg.json')->assertOk();
    Http::assertNothingSent();
});

it('does not leak a locally-hosted but inaccessible package name to the upstream', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Composer]);
    // Package is hosted by this organization but is NOT assigned to this group. The org
    // has to match: the guard is organization-scoped, and "not assigned" is what this
    // test is about — a foreign organization's name deliberately falls through instead
    // (OrgScopedUpstreamFallthroughTest).
    $secret = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Composer, 'name' => 'private/secret']);
    PackageVersion::factory()->for($secret)->create();

    Http::fake();
    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/p2/private/secret.json')->assertNotFound();
    Http::assertNothingSent(); // the private name must never reach packagist
});

it('returns 502 when the upstream errors', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.packagist.org']);
    Http::fake(['*' => Http::response('boom', 500)]);

    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/p2/some/pkg.json')->assertStatus(502);
});

it('does not fabricate a dist for source-only upstream versions', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.packagist.org']);
    Http::fake(['*/p2/meta/pkg.json' => Http::response([
        'packages' => ['meta/pkg' => [[
            'name' => 'meta/pkg', 'version' => '1.0.0', 'version_normalized' => '1.0.0.0',
            // no dist block (metapackage / source-only)
        ]]],
    ], 200)]);

    $res = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/p2/meta/pkg.json')->assertOk()->json();
    $v = MetadataMinifier::expand($res['packages']['meta/pkg'])[0];
    expect($v)->not->toHaveKey('dist');
});
