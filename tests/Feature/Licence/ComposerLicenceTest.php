<?php

// Task 4: the Composer p2 metadata and dist endpoints now enforce the licence-bounded
// assignment (group_package.version_min/version_max), on top of the pre-existing access
// checks. The reference implementation Tasks 5-6 (npm, PyPI) copy this shape, so the cases
// below are written to generalize cleanly: filter the served version list through
// VersionEntitlement::permits()/permitsAny(), refuse an out-of-bounds dist with the same 404
// shape as an unknown version, and — the load-bearing one — never let a narrowed licence
// widen the dependency-confusion guard's upstream fallthrough.
use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Upstream;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * @param  array<string, mixed>  $body
 * @return array<int, string> the pretty version strings the p2 response lists, sorted
 */
function composerServedVersions(array $body, string $name): array
{
    return collect(MetadataMinifier::expand($body['packages'][$name]))
        ->pluck('version')->sort()->values()->all();
}

it('lists only the versions inside the group assignment\'s bounds', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/bounded']);
    foreach (['1.9.0', '2.0.0', '2.4.1', '3.0.0'] as $v) {
        PackageVersion::factory()->for($pkg)->create(['version' => $v, 'version_pretty' => "v{$v}"]);
    }
    $group->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);

    $res = $this->withHeaders(tokenHeaderFor($group))
        ->getJson(registryPath($group).'/p2/acme/bounded.json')
        ->assertOk();

    expect(composerServedVersions($res->json(), 'acme/bounded'))->toBe(['v2.0.0', 'v2.4.1']);
});

it('streams the dist for a version inside the bounds', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/bounded', 'repository_url' => null]);
    $version = PackageVersion::factory()->for($pkg)->create([
        'version' => '2.4.1', 'version_pretty' => 'v2.4.1',
        'dist_path' => "dists/{$pkg->id}/prebuilt.zip",
    ]);
    Storage::disk('artifacts')->put($version->dist_path, 'zip-bytes');
    $group->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);

    $this->withHeaders(tokenHeaderFor($group))
        ->get(registryPath($group).'/dists/acme/bounded/2.4.1.zip')
        ->assertOk()->assertHeader('content-type', 'application/zip');
});

it('404s the dist for a version at/above the exclusive upper bound', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/bounded', 'repository_url' => null]);
    $version = PackageVersion::factory()->for($pkg)->create([
        'version' => '3.0.0', 'version_pretty' => 'v3.0.0',
        'dist_path' => "dists/{$pkg->id}/prebuilt.zip",
    ]);
    Storage::disk('artifacts')->put($version->dist_path, 'zip-bytes');
    $group->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);

    $this->withHeaders(tokenHeaderFor($group))
        ->get(registryPath($group).'/dists/acme/bounded/3.0.0.zip')
        ->assertNotFound();
});

it('404s the dist for a version below the inclusive lower bound', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/bounded', 'repository_url' => null]);
    $version = PackageVersion::factory()->for($pkg)->create([
        'version' => '1.9.0', 'version_pretty' => 'v1.9.0',
        'dist_path' => "dists/{$pkg->id}/prebuilt.zip",
    ]);
    Storage::disk('artifacts')->put($version->dist_path, 'zip-bytes');
    $group->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);

    $this->withHeaders(tokenHeaderFor($group))
        ->get(registryPath($group).'/dists/acme/bounded/1.9.0.zip')
        ->assertNotFound();
});

// The "no behavior change" pin: an unbounded assignment (the shape every assignment made
// before this task has, and the only shape possible before Task 1's migration) must keep
// serving exactly what it always did. Two independent fixtures with the same version set,
// both attached without any version_min/version_max, so this is not merely "one fixture
// happens to list everything" but "unbounded serving is the same regardless of which
// otherwise-identical package asks" — a regression in the new filtering wired around every
// row would show up as a divergence between the two, not just as a wrong absolute list.
it('serves an unbounded assignment byte-identically to a second, independent unbounded fixture', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkgA = Package::factory()->inOrgOf($group)->create(['name' => 'acme/unbounded-a']);
    $pkgB = Package::factory()->inOrgOf($group)->create(['name' => 'acme/unbounded-b']);
    foreach (['1.9.0', '2.0.0', '2.4.1', '3.0.0'] as $v) {
        PackageVersion::factory()->for($pkgA)->create(['version' => $v, 'version_pretty' => "v{$v}"]);
        PackageVersion::factory()->for($pkgB)->create(['version' => $v, 'version_pretty' => "v{$v}"]);
    }
    $group->packages()->attach($pkgA);
    $group->packages()->attach($pkgB);

    $resA = $this->withHeaders(tokenHeaderFor($group))->getJson(registryPath($group).'/p2/acme/unbounded-a.json')->assertOk();
    $resB = $this->withHeaders(tokenHeaderFor($group))->getJson(registryPath($group).'/p2/acme/unbounded-b.json')->assertOk();

    $versionsA = composerServedVersions($resA->json(), 'acme/unbounded-a');
    $versionsB = composerServedVersions($resB->json(), 'acme/unbounded-b');

    expect($versionsA)->toBe(['v1.9.0', 'v2.0.0', 'v2.4.1', 'v3.0.0'])
        ->and($versionsA)->toBe($versionsB);
});

it('unions two registries\' bounds through the org endpoint, never a hull of them', function () {
    $org = Organization::factory()->create();
    $groupLow = Group::factory()->for($org)->create();
    $groupHigh = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['name' => 'acme/multi-window']);
    foreach (['2.4.1', '3.5.0', '4.1.0'] as $v) {
        PackageVersion::factory()->for($pkg)->create(['version' => $v, 'version_pretty' => "v{$v}"]);
    }
    $groupLow->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);
    $groupHigh->packages()->attach($pkg, ['version_min' => '4.0.0', 'version_max' => '5.0.0']);

    $res = $this->withHeaders(orgTokenHeaderFor($org))
        ->getJson(orgRegistryPath($org).'/p2/acme/multi-window.json')
        ->assertOk();

    expect(composerServedVersions($res->json(), 'acme/multi-window'))->toBe(['v2.4.1', 'v4.1.0']);
});

it('404s the org dist for a version that falls in neither registry\'s window', function () {
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    $groupLow = Group::factory()->for($org)->create();
    $groupHigh = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['name' => 'acme/multi-window', 'repository_url' => null]);
    $version = PackageVersion::factory()->for($pkg)->create([
        'version' => '3.5.0', 'version_pretty' => 'v3.5.0',
        'dist_path' => "dists/{$pkg->id}/prebuilt.zip",
    ]);
    Storage::disk('artifacts')->put($version->dist_path, 'zip-bytes');
    $groupLow->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);
    $groupHigh->packages()->attach($pkg, ['version_min' => '4.0.0', 'version_max' => '5.0.0']);

    $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org).'/dists/acme/multi-window/3.5.0.zip')
        ->assertNotFound();
});

it('answers a valid, empty metadata document when the bounds exclude every version, not an error', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/excluded']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => 'v1.0.0']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.5.0', 'version_pretty' => 'v1.5.0']);
    $group->packages()->attach($pkg, ['version_min' => '5.0.0', 'version_max' => '6.0.0']);

    $res = $this->withHeaders(tokenHeaderFor($group))
        ->getJson(registryPath($group).'/p2/acme/excluded.json')
        ->assertOk();

    expect($res->json('packages'))->toBe(['acme/excluded' => []]);
});

// The load-bearing test: the dependency-confusion guard
// (ResolvesRegistryPackage::packageExistsLocally()) must keep suppressing the upstream
// fallthrough purely on ASSIGNMENT, never on whether a bound admits any particular version.
//
// A live assignment can't exercise this: since Task 4, every row the guard's clauses admit
// with a live assignment is a row findLocal() already resolves and serves, so resolution
// answers first and the guard is never reached (see SharedPackageUpstreamTest's closing
// comment block, which measures the same thing for the pre-existing shared-package guard).
// The one state that reaches the guard through HTTP is a LAPSED assignment: findLocal()
// excludes it (RegistryAccessService::availablePackages() reads Group::assignedPackages(),
// which filters out an expired row), but packageExistsLocally() reads the unfiltered
// Group::packages() and still counts it — mirroring
// SharedPackageUpstreamTest::'does not send a shared composer name whose assignment lapsed
// upstream'. This fixture is that same shape, with a version_min/version_max recorded on the
// very (lapsed) row the guard reads: a future regression that made the guard bounds-aware —
// e.g. by requiring the pivot's bounds to admit some version before counting the name as
// hosted — would sail straight through an assertion that never puts a bound in play. This one
// does, and does go red under exactly that mutation (verified by hand while writing this
// test, not merely asserted here).
it('does not fall through to the upstream for a lapsed shared assignment that carried a narrow licence window', function () {
    Http::fake(['*' => Http::response(['minified' => 'composer/2.0', 'packages' => []], 200)]);
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.packagist.org', 'policy' => UpstreamPolicy::Proxy]);

    $operator = Organization::factory()->create(['is_operator' => true]);
    $shared = Package::factory()->for($operator)->create(['name' => 'acme/narrow', 'type' => PackageType::Composer, 'shared' => true]);
    PackageVersion::factory()->for($shared)->create(['version' => '2.4.1', 'version_pretty' => 'v2.4.1']);
    $group->packages()->attach($shared, [
        'version_min' => '2.0.0', 'version_max' => '3.0.0',
        'available_until' => now()->subDay(),
    ]);

    // 404 (name stays local, same shape as an unfiltered lapsed assignment) — not the 200 an
    // upstream fallthrough would answer with.
    $this->withHeaders(tokenHeaderFor($group))
        ->getJson(registryPath($group).'/p2/acme/narrow.json')
        ->assertNotFound();

    Http::assertNothingSent();
});
