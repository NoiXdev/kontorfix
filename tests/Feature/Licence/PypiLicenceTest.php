<?php

// Task 6: the PyPI simple index (PEP 503 HTML and PEP 691 JSON) and the file download
// endpoint now enforce the licence-bounded assignment (group_package.version_min/
// version_max), mirroring Task 4's Composer implementation (ComposerLicenceTest.php) and
// Task 5's npm implementation (NpmLicenceTest.php): filter the served dist list through
// VersionEntitlement::permits()/permitsAny(), refuse an out-of-bounds download with the same
// 404 shape as an unknown file, and never let a narrowed licence widen the
// dependency-confusion guard's upstream fallthrough.
//
// PyPI's own wrinkle, with no Composer/npm counterpart: the comparison runs through PEP 440
// (App\Support\Licence\Pep440Version), not semver, and VersionEntitlement fails CLOSED for a
// version it cannot parse — but only once bounds are actually set; an unlimited assignment
// still serves everything, unparseable versions included, because no filtering happens at
// all in that case. Both directions are pinned below.
//
// PyPI also lists PythonDist ROWS (filename-keyed), not versions — several files can share
// one version — so the filter is applied per row, using that row's own stored `version`.
use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PythonDist;
use App\Models\Upstream;
use Illuminate\Support\Facades\Storage;

const PYPI_JSON_ACCEPT = 'application/vnd.pypi.simple.v1+json';

it('lists only the dists inside the group assignment\'s bounds, in both the JSON and HTML representations', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Python, 'name' => 'acme-bounded', 'repository_url' => null]);
    foreach (['1.9.0', '2.0.0', '2.4.1', '3.0.0'] as $v) {
        $dist = PythonDist::factory()->for($pkg)->create(['version' => $v, 'filename' => "acme_bounded-{$v}.tar.gz"]);
        Storage::disk('artifacts')->put($dist->path, 'bytes');
    }
    $group->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);

    $jsonRes = $this->withHeaders(tokenHeaderFor($group) + ['Accept' => PYPI_JSON_ACCEPT])
        ->get(registryPath($group).'/simple/acme-bounded/')
        ->assertOk();
    expect($jsonRes->json('versions'))->toEqualCanonicalizing(['2.0.0', '2.4.1']);

    $htmlRes = $this->withHeaders(tokenHeaderFor($group))
        ->get(registryPath($group).'/simple/acme-bounded/')
        ->assertOk();
    $htmlRes->assertSee('acme_bounded-2.0.0.tar.gz', false);
    $htmlRes->assertSee('acme_bounded-2.4.1.tar.gz', false);
    $htmlRes->assertDontSee('acme_bounded-1.9.0.tar.gz', false);
    $htmlRes->assertDontSee('acme_bounded-3.0.0.tar.gz', false);
});

it('streams the download for a version inside the bounds', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Python, 'name' => 'acme-bounded', 'repository_url' => null]);
    $dist = PythonDist::factory()->for($pkg)->create(['version' => '2.4.1', 'filename' => 'acme_bounded-2.4.1.tar.gz']);
    Storage::disk('artifacts')->put($dist->path, 'bytes');
    $group->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);

    $this->withHeaders(tokenHeaderFor($group))
        ->get(registryPath($group)."/pypi/files/{$pkg->id}/acme_bounded-2.4.1.tar.gz")
        ->assertOk();
});

it('404s the download for a version at/above the exclusive upper bound', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Python, 'name' => 'acme-bounded', 'repository_url' => null]);
    $dist = PythonDist::factory()->for($pkg)->create(['version' => '3.0.0', 'filename' => 'acme_bounded-3.0.0.tar.gz']);
    Storage::disk('artifacts')->put($dist->path, 'bytes');
    $group->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);

    $this->withHeaders(tokenHeaderFor($group))
        ->get(registryPath($group)."/pypi/files/{$pkg->id}/acme_bounded-3.0.0.tar.gz")
        ->assertNotFound();
});

// The PyPI-specific fail-closed pin: with bounds SET, a version PEP 440 cannot read is not
// permitted — the `+` local-version segment is deliberately unmatched by Pep440Version's
// pattern (see its docblock), so this version is unparseable by construction, not by
// accident. A version string distinct from VersionEntitlementTest's own unparseable-version
// fixtures ('1.0+cu118' et al.) — the dedupe that logs a warning only once per version
// string is process-lifetime (static), so reusing one already logged by a sibling test file
// would make that file's "logs ... exactly once" assertions depend on suite run order.
it('hides a dist whose version pep440 cannot parse when bounds are set, and 404s its download', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Python, 'name' => 'acme-oddball', 'repository_url' => null]);
    $dist = PythonDist::factory()->for($pkg)->create(['version' => '1.0+pypilicencetest', 'filename' => 'acme_oddball-1.0+pypilicencetest.tar.gz']);
    Storage::disk('artifacts')->put($dist->path, 'bytes');
    $group->packages()->attach($pkg, ['version_min' => '1.0', 'version_max' => '2.0']);

    $jsonRes = $this->withHeaders(tokenHeaderFor($group) + ['Accept' => PYPI_JSON_ACCEPT])
        ->get(registryPath($group).'/simple/acme-oddball/')
        ->assertOk();
    expect($jsonRes->json('files'))->toBe([]);

    $this->withHeaders(tokenHeaderFor($group))
        ->get(registryPath($group)."/pypi/files/{$pkg->id}/acme_oddball-1.0+pypilicencetest.tar.gz")
        ->assertNotFound();
});

// The other direction of the same pin: with NO bounds at all, nothing is filtered — an
// unparseable version is served exactly as it always was.
it('serves a dist whose version pep440 cannot parse normally when the assignment is unbounded', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Python, 'name' => 'acme-oddball', 'repository_url' => null]);
    $dist = PythonDist::factory()->for($pkg)->create(['version' => '1.0+pypilicencetest', 'filename' => 'acme_oddball-1.0+pypilicencetest.tar.gz']);
    Storage::disk('artifacts')->put($dist->path, 'bytes');
    $group->packages()->attach($pkg);

    $jsonRes = $this->withHeaders(tokenHeaderFor($group) + ['Accept' => PYPI_JSON_ACCEPT])
        ->get(registryPath($group).'/simple/acme-oddball/')
        ->assertOk();
    expect($jsonRes->json('versions'))->toBe(['1.0+pypilicencetest']);

    $this->withHeaders(tokenHeaderFor($group))
        ->get(registryPath($group)."/pypi/files/{$pkg->id}/acme_oddball-1.0+pypilicencetest.tar.gz")
        ->assertOk();
});

// PEP 440 ordering: a pre-release sorts BELOW its final release, so `1.0rc1` falls below the
// inclusive lower bound `1.0` even though a naive string/semver comparison might place it
// differently.
it('excludes a pre-release version that pep440 sorts below the inclusive lower bound', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Python, 'name' => 'acme-pre', 'repository_url' => null]);
    $dist = PythonDist::factory()->for($pkg)->create(['version' => '1.0rc1', 'filename' => 'acme_pre-1.0rc1.tar.gz']);
    Storage::disk('artifacts')->put($dist->path, 'bytes');
    $group->packages()->attach($pkg, ['version_min' => '1.0', 'version_max' => '2.0']);

    $jsonRes = $this->withHeaders(tokenHeaderFor($group) + ['Accept' => PYPI_JSON_ACCEPT])
        ->get(registryPath($group).'/simple/acme-pre/')
        ->assertOk();

    expect($jsonRes->json('versions'))->toBe([]);
});

// The "no behavior change" pin: an unbounded assignment (the shape every assignment made
// before this task has) must keep serving exactly what it always did. Two independent
// fixtures with the same version set, both attached without any version_min/version_max, so
// this is not merely "one fixture happens to list everything" but "unbounded serving is the
// same regardless of which otherwise-identical package asks."
it('serves an unbounded assignment\'s index identically to a second, independent unbounded fixture', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkgA = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Python, 'name' => 'acme-unbounded-a', 'repository_url' => null]);
    $pkgB = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Python, 'name' => 'acme-unbounded-b', 'repository_url' => null]);
    foreach (['1.9.0', '2.0.0', '2.4.1', '3.0.0'] as $v) {
        $distA = PythonDist::factory()->for($pkgA)->create(['version' => $v, 'filename' => "acme_unbounded_a-{$v}.tar.gz"]);
        Storage::disk('artifacts')->put($distA->path, 'bytes');
        $distB = PythonDist::factory()->for($pkgB)->create(['version' => $v, 'filename' => "acme_unbounded_b-{$v}.tar.gz"]);
        Storage::disk('artifacts')->put($distB->path, 'bytes');
    }
    $group->packages()->attach($pkgA);
    $group->packages()->attach($pkgB);

    $resA = $this->withHeaders(tokenHeaderFor($group) + ['Accept' => PYPI_JSON_ACCEPT])
        ->get(registryPath($group).'/simple/acme-unbounded-a/')
        ->assertOk();
    $resB = $this->withHeaders(tokenHeaderFor($group) + ['Accept' => PYPI_JSON_ACCEPT])
        ->get(registryPath($group).'/simple/acme-unbounded-b/')
        ->assertOk();

    $versionsA = $resA->json('versions');
    $versionsB = $resB->json('versions');
    sort($versionsA);
    sort($versionsB);

    expect($versionsA)->toBe(['1.9.0', '2.0.0', '2.4.1', '3.0.0'])
        ->and($versionsA)->toBe($versionsB);
});

it('unions two registries\' bounds through the org endpoint, never a hull of them', function () {
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    $groupLow = Group::factory()->for($org)->create();
    $groupHigh = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['type' => PackageType::Python, 'name' => 'acme-multi-window', 'repository_url' => null]);
    foreach (['2.4.1', '3.5.0', '4.1.0'] as $v) {
        $dist = PythonDist::factory()->for($pkg)->create(['version' => $v, 'filename' => "acme_multi_window-{$v}.tar.gz"]);
        Storage::disk('artifacts')->put($dist->path, 'bytes');
    }
    $groupLow->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);
    $groupHigh->packages()->attach($pkg, ['version_min' => '4.0.0', 'version_max' => '5.0.0']);

    $res = $this->withHeaders(orgTokenHeaderFor($org) + ['Accept' => PYPI_JSON_ACCEPT])
        ->get(orgRegistryPath($org).'/simple/acme-multi-window/')
        ->assertOk();

    expect($res->json('versions'))->toEqualCanonicalizing(['2.4.1', '4.1.0']);
});

it('404s the org download for a version that falls in neither registry\'s window', function () {
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    $groupLow = Group::factory()->for($org)->create();
    $groupHigh = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['type' => PackageType::Python, 'name' => 'acme-multi-window', 'repository_url' => null]);
    $dist = PythonDist::factory()->for($pkg)->create(['version' => '3.5.0', 'filename' => 'acme_multi_window-3.5.0.tar.gz']);
    Storage::disk('artifacts')->put($dist->path, 'bytes');
    $groupLow->packages()->attach($pkg, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);
    $groupHigh->packages()->attach($pkg, ['version_min' => '4.0.0', 'version_max' => '5.0.0']);

    $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org)."/pypi/files/{$pkg->id}/acme_multi_window-3.5.0.tar.gz")
        ->assertNotFound();
});

// The load-bearing test: the dependency-confusion guard (PypiController::pythonExistsLocally())
// must keep suppressing the upstream REDIRECT branch of simpleProject() purely on ASSIGNMENT,
// never on whether a bound admits any particular version — and, unlike Composer/npm, that
// branch is a 302 `redirect()->away()`, not an outbound HTTP client call, so the assertion here
// is on the response itself (no Location header, plain 404) rather than on Http::fake().
//
// A live assignment can't exercise this: since pythonPackagesOfGroup() already resolves and
// serves any row a live assignment admits, resolution answers first and the guard is never
// reached (see ComposerLicenceTest's identically-reasoned case, and SharedPackageUpstreamTest's
// closing comment block, which measures the same thing for the pre-existing shared-package
// guard). The one state that reaches the guard through HTTP is a LAPSED assignment:
// pythonPackagesOfGroup() reads Group::assignedPackages() and excludes it, but
// pythonExistsLocally() reads the unfiltered Group::packages() and still counts it. This
// fixture carries a version_min/version_max on the very (lapsed) row the guard reads, so a
// future regression that made the guard bounds-aware would sail straight through an assertion
// that never puts a bound in play — this one does.
it('does not fall through to the upstream redirect for a lapsed shared assignment that carried a narrow licence window', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Python, 'url' => 'https://pypi.org', 'enabled' => true]);

    $operator = Organization::factory()->create(['is_operator' => true]);
    $shared = Package::factory()->for($operator)->create(['type' => PackageType::Python, 'name' => 'acme-narrow', 'shared' => true]);
    PythonDist::factory()->for($shared)->create(['version' => '2.4.1', 'filename' => 'acme_narrow-2.4.1.tar.gz']);
    $group->packages()->attach($shared, [
        'version_min' => '2.0.0', 'version_max' => '3.0.0',
        'available_until' => now()->subDay(),
    ]);

    // 404 (name stays local, same shape as an unfiltered lapsed assignment) — never the 302
    // redirect an upstream fallthrough would answer with.
    $res = $this->withHeaders(tokenHeaderFor($group))
        ->get(registryPath($group).'/simple/acme-narrow/');

    $res->assertNotFound();
    expect($res->headers->get('Location'))->toBeNull();
});
