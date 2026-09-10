<?php

// The org-level `/o/{orgSlug}` npm endpoint (spec 2026-09-10-org-level-registry-and-
// portal-setup-design.md). NpmController branches on `registryOrganization` (set by
// ResolveRegistryContext for this mount, `registryGroup` staying null there) before falling
// through to the existing per-registry path unchanged — the exact pattern
// OrgComposerEndpointTest.php pins for Composer (Task 3), copied here for npm: the two
// German 403 messages, the 401-before-403-before-package-lookup ordering, and the
// "version_constraint is not enforced on any path" pin (the group path never filters npm
// versions by it either, so org mode matching group mode means matching an already-unfiltered
// response, not inventing new filtering).
use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\RegistryToken;
use Illuminate\Support\Facades\Storage;

it('serves a packument for a package visible through the org, reachable via any of its groups', function () {
    $org = Organization::factory()->create();
    $groupA = Group::factory()->for($org)->create();
    $groupB = Group::factory()->for($org)->create();

    $pkgA = Package::factory()->inOrgOf($groupA)->create(['type' => PackageType::Npm, 'name' => 'acme-a']);
    PackageVersion::factory()->for($pkgA)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'acme-a-1.0.0.tgz']);
    $groupA->packages()->attach($pkgA);

    $pkgB = Package::factory()->inOrgOf($groupB)->create(['type' => PackageType::Npm, 'name' => 'acme-b']);
    PackageVersion::factory()->for($pkgB)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'acme-b-1.0.0.tgz']);
    $groupB->packages()->attach($pkgB);

    $operator = Organization::factory()->create(['is_operator' => true]);
    $shared = Package::factory()->for($operator)->create(['type' => PackageType::Npm, 'name' => 'acme-shared', 'shared' => true]);
    PackageVersion::factory()->for($shared)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'acme-shared-1.0.0.tgz']);
    $groupA->packages()->attach($shared);

    $headers = orgTokenHeaderFor($org);

    $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/acme-a')
        ->assertOk()->assertJsonPath('name', 'acme-a');
    $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/acme-b')
        ->assertOk()->assertJsonPath('name', 'acme-b');
    $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/acme-shared')
        ->assertOk()->assertJsonPath('name', 'acme-shared');
});

it('serves a scoped packument through the org endpoint', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Npm, 'name' => '@acme/ui-kit']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => ['name' => '@acme/ui-kit'], 'dist_tarball_name' => 'ui-kit-1.0.0.tgz']);
    $group->packages()->attach($pkg);

    $this->withHeaders(orgTokenHeaderFor($org))
        ->getJson(orgRegistryPath($org).'/@acme/ui-kit')
        ->assertOk()->assertJsonPath('name', '@acme/ui-kit');
});

it('serves every version on /o/ regardless of version_constraint on the assignment, same as the group endpoint (unfiltered on every path today)', function () {
    // group_package.version_constraint is not enforced at serve time on ANY path — see
    // NpmMetadataBuilder (there is no filtering there either) and
    // ComposerMetadataBuilder's docblock, which pins the same fact for Composer. The spec's
    // "union of what any single group would serve" therefore reduces to "everything",
    // because that is what the (unfiltered) group endpoint already serves. Pinned here as
    // an equality against the group endpoint's own response for the same package, rather
    // than against a hardcoded version list, so this test breaks the day either path starts
    // enforcing the column without the other.
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['type' => PackageType::Npm, 'name' => 'acme-lib']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'acme-lib-1.0.0.tgz']);
    PackageVersion::factory()->for($pkg)->create(['version' => '2.0.0', 'version_pretty' => '2.0.0', 'metadata' => [], 'dist_tarball_name' => 'acme-lib-2.0.0.tgz']);
    // A constraint IS present on the assignment — proving it has no effect, not merely
    // absent from this scenario.
    $group->packages()->attach($pkg, ['version_constraint' => '^1.0']);

    $orgRes = $this->withHeaders(orgTokenHeaderFor($org))->getJson(orgRegistryPath($org).'/acme-lib');
    $groupRes = $this->withHeaders(tokenHeaderFor($group))->getJson(registryPath($group).'/acme-lib');

    $orgRes->assertOk();
    $groupRes->assertOk();
    $orgVersions = array_keys($orgRes->json('versions'));
    $groupVersions = array_keys($groupRes->json('versions'));
    sort($orgVersions);
    sort($groupVersions);

    expect($orgVersions)->toBe(['1.0.0', '2.0.0']);
    expect($orgVersions)->toBe($groupVersions);
});

it('serves a tarball download through the org endpoint', function () {
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['type' => PackageType::Npm, 'name' => 'acme-demo']);
    $version = PackageVersion::factory()->for($pkg)->create([
        'version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [],
        'dist_tarball_name' => 'acme-demo-1.0.0.tgz',
        'dist_path' => "tarballs/{$pkg->id}/acme-demo-1.0.0.tgz",
    ]);
    Storage::disk('artifacts')->put($version->dist_path, 'tarball-bytes');
    $group->packages()->attach($pkg);

    $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org).'/acme-demo/-/acme-demo-1.0.0.tgz')
        ->assertOk()->assertHeader('content-type', 'application/octet-stream');
});

it('serves a scoped tarball download through the org endpoint', function () {
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['type' => PackageType::Npm, 'name' => '@acme/ui-kit']);
    $version = PackageVersion::factory()->for($pkg)->create([
        'version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [],
        'dist_tarball_name' => 'ui-kit-1.0.0.tgz',
        'dist_path' => "tarballs/{$pkg->id}/ui-kit-1.0.0.tgz",
    ]);
    Storage::disk('artifacts')->put($version->dist_path, 'scoped-bytes');
    $group->packages()->attach($pkg);

    $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org).'/@acme/ui-kit/-/ui-kit-1.0.0.tgz')
        ->assertOk();
});

it('answers 401 with the same challenge as the group endpoint for an anonymous request', function () {
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create();

    $orgRes = $this->getJson(orgRegistryPath($org).'/acme-demo');
    $groupRes = $this->getJson(registryPath(Group::factory()->for(Organization::factory())->create()).'/acme-demo');

    $orgRes->assertUnauthorized();
    $groupRes->assertUnauthorized();
    expect($orgRes->json('message'))->toBe($groupRes->json('message'));
    expect(array_keys($orgRes->headers->all()))->toEqual(array_keys($groupRes->headers->all()));
});

it('refuses a group-bound token of the same organization with the exact German message, before any package lookup', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $secret = Package::factory()->for($org)->create(['type' => PackageType::Npm, 'name' => 'very-secret-name']);
    PackageVersion::factory()->for($secret)->create(['metadata' => [], 'dist_tarball_name' => 'x.tgz']);
    $group->packages()->attach($secret);

    [, $plain] = RegistryToken::issue($org, 'group-bound', $group);
    $headers = ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];

    $res = $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/very-secret-name');

    $res->assertForbidden();
    expect($res->json('message'))
        ->toBe('Dieses Token gilt nur für eine einzelne Registry — für die organisationsweite Quelle wird ein organisationsweites Token benötigt.');
    expect($res->getContent())->not->toContain('very-secret-name');
});

it('refuses a foreign organization\'s org-wide token with the generic German message, before any package lookup', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $secret = Package::factory()->for($org)->create(['type' => PackageType::Npm, 'name' => 'very-secret-name']);
    PackageVersion::factory()->for($secret)->create(['metadata' => [], 'dist_tarball_name' => 'x.tgz']);
    $group->packages()->attach($secret);

    $foreignOrg = Organization::factory()->create();

    $res = $this->withHeaders(orgTokenHeaderFor($foreignOrg))
        ->getJson(orgRegistryPath($org).'/very-secret-name');

    $res->assertForbidden();
    expect($res->json('message'))->toBe('Kein Zugriff auf diese Organisation.');
    expect($res->getContent())->not->toContain('very-secret-name');
});

it('refuses a foreign organization\'s group-bound token with the generic German message', function () {
    $org = Organization::factory()->create();
    $foreignGroup = Group::factory()->for(Organization::factory())->create();

    [, $plain] = RegistryToken::issue($foreignGroup->organization, 'foreign', $foreignGroup);
    $headers = ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];

    $res = $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/acme-demo');

    $res->assertForbidden();
    expect($res->json('message'))->toBe('Kein Zugriff auf diese Organisation.');
});

it('a publish-ability org token still only reads (access, not ability, gates the endpoint)', function () {
    // Not a brief case, but the same cheap insurance OrgComposerEndpointTest carries:
    // canAccessOrganization() must not have grown a dependency on ability.
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['type' => PackageType::Npm, 'name' => 'acme-demo']);
    PackageVersion::factory()->for($pkg)->create(['metadata' => [], 'dist_tarball_name' => 'x.tgz']);
    $group->packages()->attach($pkg);

    [, $plain] = RegistryToken::issue($org, 'ci', null, TokenAbility::Publish);
    $headers = ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];

    $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/acme-demo')->assertOk();
});

it('returns 404 for a package name not visible to the organization, unknown-name shaped', function () {
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create();

    $res = $this->withHeaders(orgTokenHeaderFor($org))->getJson(orgRegistryPath($org).'/does-not-exist');

    $res->assertNotFound();

    // Same shape as the group endpoint's own 404 for an unknown npm package name (no
    // upstream configured): a plain framework 404, not a bespoke body — npm's protocol
    // itself has no error envelope this app manufactures on any path. Compared by the
    // response's top-level keys and message, not a byte-for-byte body diff: in debug mode
    // the framework's 404 body also carries a stack trace, and the two requests take
    // different call paths (org branch vs. group branch) so their trace arrays legitimately
    // differ in depth without either response being a different KIND of 404.
    $group = Group::factory()->for($org)->create();
    $groupRes = $this->withHeaders(tokenHeaderFor($group))
        ->getJson(registryPath($group).'/does-not-exist');
    $groupRes->assertNotFound();
    expect(array_keys($res->json()))->toBe(array_keys($groupRes->json()));
    expect($res->json('message'))->toBe($groupRes->json('message'));
});

it('unknown org slug still 404s under the npm packument path', function () {
    $this->getJson('/o/no-such-org/acme-demo')->assertNotFound();
});

it('denies a tarball download for a package not visible to the organization', function () {
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'acme-demo']); // different org, not shared
    PackageVersion::factory()->for($pkg)->create(['metadata' => [], 'dist_tarball_name' => 'acme-demo-1.0.0.tgz', 'dist_path' => 'x']);

    $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org).'/acme-demo/-/acme-demo-1.0.0.tgz')
        ->assertNotFound();
});
