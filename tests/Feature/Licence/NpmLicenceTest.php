<?php

// Task 5: the npm packument and tarball endpoints now enforce the licence-bounded assignment
// (group_package.version_min/version_max), mirroring Task 4's Composer implementation
// (ComposerLicenceTest.php) — filter the served version list through
// VersionEntitlement::permits()/permitsAny(), refuse an out-of-bounds tarball with the same
// 404 shape as an unknown version, and never let a narrowed licence widen the
// dependency-confusion guard's upstream fallthrough.
//
// npm's own wrinkle, with no Composer counterpart: `dist-tags` must be pruned against the
// surviving version set after filtering. A `latest` (or any other tag) pointing at a
// filtered-out version is exactly the breakage the hidden-not-403 decision exists to avoid —
// `npm install <pkg>` resolves `latest` first and fails outright if it 404s. After filtering,
// `latest` is repointed to the highest surviving version, and any tag with no surviving target
// is dropped entirely.
use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Upstream;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('lists only the versions inside the group assignment\'s bounds and repoints latest to the highest surviving version', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create([
        'name' => 'acme-bounded',
        'type' => PackageType::Npm,
        'dist_tags' => ['latest' => '3.0.0'],
    ]);
    foreach (['1.9.0', '2.0.0', '2.4.1', '3.0.0'] as $v) {
        PackageVersion::factory()->for($pkg)->create([
            'version' => $v, 'version_pretty' => $v, 'metadata' => [],
            'dist_tarball_name' => "acme-bounded-{$v}.tgz",
        ]);
    }
    $group->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);

    $res = $this->withHeaders(tokenHeaderFor($group))
        ->getJson(registryPath($group).'/acme-bounded')
        ->assertOk();

    expect(array_keys($res->json('versions')))->toEqualCanonicalizing(['2.0.0', '2.4.1'])
        ->and($res->json('dist-tags.latest'))->toBe('2.4.1');
});

it('drops a dist-tag entirely when its target version did not survive filtering', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create([
        'name' => 'acme-tagged',
        'type' => PackageType::Npm,
        'dist_tags' => ['latest' => '3.0.0', 'next' => '3.0.0'],
    ]);
    foreach (['1.9.0', '2.0.0', '2.4.1', '3.0.0'] as $v) {
        PackageVersion::factory()->for($pkg)->create([
            'version' => $v, 'version_pretty' => $v, 'metadata' => [],
            'dist_tarball_name' => "acme-tagged-{$v}.tgz",
        ]);
    }
    $group->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);

    $res = $this->withHeaders(tokenHeaderFor($group))
        ->getJson(registryPath($group).'/acme-tagged')
        ->assertOk();

    expect($res->json('dist-tags'))->not->toHaveKey('next');
});

it('streams the tarball for a version inside the bounds', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme-bounded', 'type' => PackageType::Npm]);
    $version = PackageVersion::factory()->for($pkg)->create([
        'version' => '2.4.1', 'version_pretty' => '2.4.1', 'metadata' => [],
        'dist_tarball_name' => 'acme-bounded-2.4.1.tgz',
        'dist_path' => "tarballs/{$pkg->id}/acme-bounded-2.4.1.tgz",
    ]);
    Storage::disk('artifacts')->put($version->dist_path, 'tarball-bytes');
    $group->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);

    $this->withHeaders(tokenHeaderFor($group))
        ->get(registryPath($group).'/acme-bounded/-/acme-bounded-2.4.1.tgz')
        ->assertOk()->assertHeader('content-type', 'application/octet-stream');
});

it('404s the tarball for a version at/above the exclusive upper bound', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme-bounded', 'type' => PackageType::Npm]);
    $version = PackageVersion::factory()->for($pkg)->create([
        'version' => '3.0.0', 'version_pretty' => '3.0.0', 'metadata' => [],
        'dist_tarball_name' => 'acme-bounded-3.0.0.tgz',
        'dist_path' => "tarballs/{$pkg->id}/acme-bounded-3.0.0.tgz",
    ]);
    Storage::disk('artifacts')->put($version->dist_path, 'tarball-bytes');
    $group->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);

    $this->withHeaders(tokenHeaderFor($group))
        ->get(registryPath($group).'/acme-bounded/-/acme-bounded-3.0.0.tgz')
        ->assertNotFound();
});

// Reachable in practice: an operator typo in version_min/version_max, or a customer licensed
// for a range that has no published versions yet. The spec's error table calls this "empty
// (valid) version list" — a 200 with an empty packument, not an error. npm's packument shape
// for both `versions` and `dist-tags` is a JSON OBJECT ({}), never an array ([]); PHP's
// json_encode() renders an empty associative array as `[]`, which is why this is asserted on
// the raw response body rather than the decoded one — decoding `{}` and `[]` both back into an
// empty PHP array would make the two indistinguishable and let exactly this regression through.
it('answers a valid, empty packument (versions and dist-tags as objects, not arrays) when the bounds exclude every version', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create([
        'name' => 'acme-excluded',
        'type' => PackageType::Npm,
        'dist_tags' => ['latest' => '1.5.0'],
    ]);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'acme-excluded-1.0.0.tgz']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.5.0', 'version_pretty' => '1.5.0', 'metadata' => [], 'dist_tarball_name' => 'acme-excluded-1.5.0.tgz']);
    $group->packages()->attach($pkg, ['version_min' => '5.0.0', 'version_max' => '6.0.0']);

    $res = $this->withHeaders(tokenHeaderFor($group))
        ->getJson(registryPath($group).'/acme-excluded')
        ->assertOk();

    expect($res->json('versions'))->toBe([])
        ->and($res->json('dist-tags'))->toBe([]);
    expect($res->getContent())->toContain('"versions":{}')
        ->and($res->getContent())->toContain('"dist-tags":{}');
});

// The "no behavior change" pin: an unbounded assignment (the shape every assignment made
// before this task has) must keep serving exactly what it always did — the same equality
// approach ComposerLicenceTest uses, adapted to compare two independent unbounded fixtures'
// full packument shape (versions AND dist-tags) rather than only the version list, since
// dist-tags pruning is the part of this task Composer has no counterpart for.
it('serves an unbounded assignment\'s packument identically to a second, independent unbounded fixture', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkgA = Package::factory()->inOrgOf($group)->create(['name' => 'acme-unbounded-a', 'type' => PackageType::Npm, 'dist_tags' => ['latest' => '2.4.1']]);
    $pkgB = Package::factory()->inOrgOf($group)->create(['name' => 'acme-unbounded-b', 'type' => PackageType::Npm, 'dist_tags' => ['latest' => '2.4.1']]);
    foreach (['1.9.0', '2.0.0', '2.4.1', '3.0.0'] as $v) {
        PackageVersion::factory()->for($pkgA)->create(['version' => $v, 'version_pretty' => $v, 'metadata' => [], 'dist_tarball_name' => "acme-unbounded-a-{$v}.tgz"]);
        PackageVersion::factory()->for($pkgB)->create(['version' => $v, 'version_pretty' => $v, 'metadata' => [], 'dist_tarball_name' => "acme-unbounded-b-{$v}.tgz"]);
    }
    $group->packages()->attach($pkgA);
    $group->packages()->attach($pkgB);

    $resA = $this->withHeaders(tokenHeaderFor($group))->getJson(registryPath($group).'/acme-unbounded-a')->assertOk();
    $resB = $this->withHeaders(tokenHeaderFor($group))->getJson(registryPath($group).'/acme-unbounded-b')->assertOk();

    $versionsA = array_keys($resA->json('versions'));
    $versionsB = array_keys($resB->json('versions'));
    sort($versionsA);
    sort($versionsB);

    expect($versionsA)->toBe(['1.9.0', '2.0.0', '2.4.1', '3.0.0'])
        ->and($versionsA)->toBe($versionsB)
        ->and($resA->json('dist-tags'))->toBe(['latest' => '2.4.1'])
        ->and($resA->json('dist-tags'))->toBe($resB->json('dist-tags'));
});

it('unions two registries\' bounds through the org endpoint, never a hull of them', function () {
    $org = Organization::factory()->create();
    $groupLow = Group::factory()->for($org)->create();
    $groupHigh = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['name' => 'acme-multi-window', 'type' => PackageType::Npm, 'dist_tags' => ['latest' => '4.1.0']]);
    foreach (['2.4.1', '3.5.0', '4.1.0'] as $v) {
        PackageVersion::factory()->for($pkg)->create(['version' => $v, 'version_pretty' => $v, 'metadata' => [], 'dist_tarball_name' => "acme-multi-window-{$v}.tgz"]);
    }
    $groupLow->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);
    $groupHigh->packages()->attach($pkg, ['version_min' => '4.0.0', 'version_max' => '5.0.0']);

    $res = $this->withHeaders(orgTokenHeaderFor($org))
        ->getJson(orgRegistryPath($org).'/acme-multi-window')
        ->assertOk();

    expect(array_keys($res->json('versions')))->toEqualCanonicalizing(['2.4.1', '4.1.0']);
});

it('404s the org tarball for a version that falls in neither registry\'s window', function () {
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    $groupLow = Group::factory()->for($org)->create();
    $groupHigh = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['name' => 'acme-multi-window', 'type' => PackageType::Npm]);
    $version = PackageVersion::factory()->for($pkg)->create([
        'version' => '3.5.0', 'version_pretty' => '3.5.0', 'metadata' => [],
        'dist_tarball_name' => 'acme-multi-window-3.5.0.tgz',
        'dist_path' => "tarballs/{$pkg->id}/acme-multi-window-3.5.0.tgz",
    ]);
    Storage::disk('artifacts')->put($version->dist_path, 'tarball-bytes');
    $groupLow->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);
    $groupHigh->packages()->attach($pkg, ['version_min' => '4.0.0', 'version_max' => '5.0.0']);

    $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org).'/acme-multi-window/-/acme-multi-window-3.5.0.tgz')
        ->assertNotFound();
});

// The load-bearing test: the dependency-confusion guard
// (ResolvesRegistryPackage::packageExistsLocally()) must keep suppressing the upstream
// fallthrough purely on ASSIGNMENT, never on whether a bound admits any particular version.
//
// A live assignment can't exercise this — since Task 4/5, every row the guard's clauses admit
// with a live assignment is a row findLocal() already resolves and serves, so resolution
// answers first and the guard is never reached (see ComposerLicenceTest's identically-reasoned
// case, and SharedPackageUpstreamTest's closing comment block, which measures the same thing
// for the pre-existing shared-package guard). The one state that reaches the guard through
// HTTP is a LAPSED assignment: findLocal() excludes it, but packageExistsLocally() reads the
// unfiltered Group::packages() and still counts it. This fixture carries a version_min/
// version_max on the very (lapsed) row the guard reads, so a future regression that made the
// guard bounds-aware would sail straight through an assertion that never puts a bound in play
// — this one does.
it('does not fall through to the upstream for a lapsed shared assignment that carried a narrow licence window', function () {
    Http::fake(['*' => Http::response(['name' => 'acme-narrow', 'versions' => []], 200)]);
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Npm, 'url' => 'https://registry.npmjs.org', 'policy' => UpstreamPolicy::Proxy]);

    $operator = Organization::factory()->create(['is_operator' => true]);
    $shared = Package::factory()->for($operator)->create(['name' => 'acme-narrow', 'type' => PackageType::Npm, 'shared' => true]);
    PackageVersion::factory()->for($shared)->create(['version' => '2.4.1', 'version_pretty' => '2.4.1', 'metadata' => [], 'dist_tarball_name' => 'acme-narrow-2.4.1.tgz']);
    $group->packages()->attach($shared, [
        'version_min' => '2.0.0', 'version_max' => '3.0.0',
        'available_until' => now()->subDay(),
    ]);

    // 404 (name stays local, same shape as an unfiltered lapsed assignment) — not the 200 an
    // upstream fallthrough would answer with.
    $this->withHeaders(tokenHeaderFor($group))
        ->getJson(registryPath($group).'/acme-narrow')
        ->assertNotFound();

    Http::assertNothingSent();
});
