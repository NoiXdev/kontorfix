<?php

// The org-level `/o/{orgSlug}` Composer endpoint (spec 2026-09-10-org-level-registry-and-
// portal-setup-design.md). ComposerController branches on `registryOrganization` (set by
// ResolveRegistryContext for this mount, `registryGroup` staying null there) before falling
// through to the existing per-registry path unchanged — see the class docblocks in
// ComposerController and ResolvesRegistryPackage for the branching pattern itself.
//
// This is the reference implementation Tasks 4 (npm) and 5 (PyPI) copy: the two 403
// messages, the 401-before-403-before-package-lookup ordering, the "version_constraint is
// not enforced on any path" pin and the "org with zero packages still answers a valid empty
// response" case all pin behavior those tasks are expected to reproduce for their own
// ecosystem.
use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\RegistryToken;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Support\Facades\Storage;

it('lists the union of packages assigned across every group of the organization, including a shared one', function () {
    $org = Organization::factory()->create();
    $groupA = Group::factory()->for($org)->create();
    $groupB = Group::factory()->for($org)->create();

    $pkgA = Package::factory()->inOrgOf($groupA)->create(['name' => 'acme/a']);
    $pkgB = Package::factory()->inOrgOf($groupB)->create(['name' => 'acme/b']);
    $groupA->packages()->attach($pkgA);
    $groupB->packages()->attach($pkgB);

    $operator = Organization::factory()->create(['is_operator' => true]);
    $shared = Package::factory()->for($operator)->create(['name' => 'acme/shared', 'shared' => true]);
    $groupA->packages()->attach($shared);

    $res = $this->withHeaders(orgTokenHeaderFor($org))->getJson(orgRegistryPath($org).'/packages.json');

    $res->assertOk()->assertJsonPath('metadata-url', orgRegistryPath($org).'/p2/%package%.json');
    expect($res->json('available-packages'))->toBe(['acme/a', 'acme/b', 'acme/shared']);
});

it('answers a valid, empty response for an organization with zero visible packages', function () {
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create(); // a group exists, but nothing is assigned to it

    $res = $this->withHeaders(orgTokenHeaderFor($org))->getJson(orgRegistryPath($org).'/packages.json');

    $res->assertOk()->assertJsonPath('available-packages', []);
});

it('serves p2 metadata for a package visible through the org', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['name' => 'acme/demo']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0.0', 'version_pretty' => 'v1.0.0']);
    $group->packages()->attach($pkg);

    $this->withHeaders(orgTokenHeaderFor($org))
        ->getJson(orgRegistryPath($org).'/p2/acme/demo.json')
        ->assertOk()->assertJsonStructure(['packages' => ['acme/demo']]);
});

it('serves every version on /o/ regardless of version_constraint on the assignment, same as the group endpoint (unfiltered on every path today)', function () {
    // group_package.version_constraint is not enforced at serve time on ANY path — see
    // ComposerMetadataBuilder's docblock. The spec's "union of what any single group would
    // serve" therefore reduces to "everything", because that is what the (unfiltered) group
    // endpoint already serves. Pinned here as an equality against the group endpoint's own
    // response for the same package, rather than against a hardcoded version list, so this
    // test breaks the day either path starts enforcing the column without the other.
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['name' => 'acme/lib']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0.0', 'version_pretty' => 'v1.0.0']);
    PackageVersion::factory()->for($pkg)->create(['version' => '2.0.0.0', 'version_pretty' => 'v2.0.0']);
    // A constraint IS present on the assignment — proving it has no effect, not merely
    // absent from this scenario.
    $group->packages()->attach($pkg, ['version_constraint' => '^1.0']);

    $orgRes = $this->withHeaders(orgTokenHeaderFor($org))->getJson(orgRegistryPath($org).'/p2/acme/lib.json');
    $groupRes = $this->withHeaders(tokenHeaderFor($group))->getJson(registryPath($group).'/p2/acme/lib.json');

    $orgRes->assertOk();
    $groupRes->assertOk();
    $orgVersions = collect(MetadataMinifier::expand($orgRes->json('packages')['acme/lib']))->pluck('version')->sort()->values()->all();
    $groupVersions = collect(MetadataMinifier::expand($groupRes->json('packages')['acme/lib']))->pluck('version')->sort()->values()->all();

    expect($orgVersions)->toBe(['v1.0.0', 'v2.0.0']);
    expect($orgVersions)->toBe($groupVersions);
});

it('serves a dist download through the org endpoint', function () {
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['name' => 'acme/demo', 'repository_url' => null]);
    $version = PackageVersion::factory()->for($pkg)->create([
        'version' => '1.0.0.0', 'version_pretty' => 'v1.0.0',
        'dist_path' => "dists/{$pkg->id}/prebuilt.zip",
    ]);
    Storage::disk('artifacts')->put($version->dist_path, 'zip-bytes');
    $group->packages()->attach($pkg);

    $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org).'/dists/acme/demo/1.0.0.0.zip')
        ->assertOk()->assertHeader('content-type', 'application/zip');
});

it('answers 401 with the same challenge as the group endpoint for an anonymous request', function () {
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create();

    $orgRes = $this->getJson(orgRegistryPath($org).'/packages.json');
    $groupRes = $this->getJson(registryPath(Group::factory()->for(Organization::factory())->create()).'/packages.json');

    $orgRes->assertUnauthorized();
    $groupRes->assertUnauthorized();
    // Same shape, not byte-identical timing-sensitive headers (e.g. `Date`) across two
    // separate requests: what the brief pins is that an org caller and a group caller see
    // the same challenge, i.e. the same status and the same message body.
    expect($orgRes->json('message'))->toBe($groupRes->json('message'));
    expect(array_keys($orgRes->headers->all()))->toEqual(array_keys($groupRes->headers->all()));
});

it('refuses a group-bound token of the same organization with the exact German message, before any package lookup', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $secret = Package::factory()->for($org)->create(['name' => 'acme/very-secret-name']);
    PackageVersion::factory()->for($secret)->create();
    $group->packages()->attach($secret);

    [, $plain] = RegistryToken::issue($org, 'group-bound', $group);
    $headers = ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];

    $res = $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/p2/acme/very-secret-name.json');

    $res->assertForbidden();
    expect($res->json('message'))
        ->toBe('Dieses Token gilt nur für eine einzelne Registry — für die organisationsweite Quelle wird ein organisationsweites Token benötigt.');
    expect($res->getContent())->not->toContain('very-secret-name');
});

it('gives a denied caller the identical 403 for a nonexistent package name as for an existing one — no existence oracle', function () {
    // The property authorizeOrganization()'s ordering exists to guarantee, stated directly
    // rather than only through the "before any package lookup" test above: a caller
    // canAccessOrganization() refuses must not be able to tell, from the response alone,
    // whether the package name they asked about exists. If package resolution ever ran
    // BEFORE authorization, a denied caller would get 404 for a name nobody registered but
    // 403 for one that exists and is merely off-limits — the 404-vs-403 split itself would
    // be the leak, independent of anything the response body says. Asserted here as an
    // equality between the two responses (status AND message), not merely "both happen to
    // be 403 individually", so a regression that changed one but not the other still reddens
    // this.
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $secret = Package::factory()->for($org)->create(['name' => 'acme/very-secret-name']);
    PackageVersion::factory()->for($secret)->create();
    $group->packages()->attach($secret);

    [, $plain] = RegistryToken::issue($org, 'group-bound', $group);
    $headers = ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];

    $existingRes = $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/p2/acme/very-secret-name.json');
    $missingRes = $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/p2/acme/does-not-exist.json');

    $existingRes->assertForbidden();
    $missingRes->assertForbidden();
    expect($missingRes->status())->toBe($existingRes->status());
    expect($missingRes->json('message'))->toBe($existingRes->json('message'));
    expect($existingRes->getContent())->not->toContain('very-secret-name');
    expect($missingRes->getContent())->not->toContain('does-not-exist');
});

it('refuses a foreign organization\'s org-wide token with the generic German message, before any package lookup', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $secret = Package::factory()->for($org)->create(['name' => 'acme/very-secret-name']);
    PackageVersion::factory()->for($secret)->create();
    $group->packages()->attach($secret);

    $foreignOrg = Organization::factory()->create();

    $res = $this->withHeaders(orgTokenHeaderFor($foreignOrg))
        ->getJson(orgRegistryPath($org).'/p2/acme/very-secret-name.json');

    $res->assertForbidden();
    expect($res->json('message'))->toBe('Kein Zugriff auf diese Organisation.');
    expect($res->getContent())->not->toContain('very-secret-name');
});

it('refuses a foreign organization\'s group-bound token with the generic German message', function () {
    $org = Organization::factory()->create();
    $foreignGroup = Group::factory()->for(Organization::factory())->create();

    [, $plain] = RegistryToken::issue($foreignGroup->organization, 'foreign', $foreignGroup);
    $headers = ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];

    $res = $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/packages.json');

    $res->assertForbidden();
    expect($res->json('message'))->toBe('Kein Zugriff auf diese Organisation.');
});

it('a publish-ability org token still only reads (access, not ability, gates the endpoint)', function () {
    // Not a brief case, but cheap insurance: canAccessOrganization() must not have grown a
    // dependency on ability while this task touched the surrounding controller code.
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['name' => 'acme/demo']);
    PackageVersion::factory()->for($pkg)->create();
    $group->packages()->attach($pkg);

    [, $plain] = RegistryToken::issue($org, 'ci', null, TokenAbility::Publish);
    $headers = ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];

    $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/p2/acme/demo.json')->assertOk();
});

it('sends the same cache/content headers as the equivalent group endpoint response', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create(['organization_id' => $org->id]);
    $pkg = Package::factory()->for($org)->create(['name' => 'acme/demo']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0.0', 'version_pretty' => 'v1.0.0']);
    $group->packages()->attach($pkg);

    $orgRes = $this->withHeaders(orgTokenHeaderFor($org))->getJson(orgRegistryPath($org).'/p2/acme/demo.json');
    $groupRes = $this->withHeaders(tokenHeaderFor($group))->getJson(registryPath($group).'/p2/acme/demo.json');

    $orgRes->assertOk();
    $groupRes->assertOk();
    expect(array_keys($orgRes->headers->all()))->toEqual(array_keys($groupRes->headers->all()));
    foreach (['content-type', 'cache-control'] as $header) {
        expect($orgRes->headers->get($header))->toBe($groupRes->headers->get($header));
    }
});

it('returns 404 for a package name not visible to the organization, unknown-name shaped', function () {
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create();

    $this->withHeaders(orgTokenHeaderFor($org))
        ->getJson(orgRegistryPath($org).'/p2/acme/does-not-exist.json')
        ->assertNotFound();
});

it('unknown org slug still 404s under the composer p2 path', function () {
    $this->getJson('/o/no-such-org/p2/acme/demo.json')->assertNotFound();
});
