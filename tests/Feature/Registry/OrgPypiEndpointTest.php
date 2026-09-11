<?php

// The org-level `/o/{orgSlug}` PyPI endpoint (spec 2026-09-10-org-level-registry-and-
// portal-setup-design.md). PypiController branches on `registryOrganization` (set by
// ResolveRegistryContext for this mount, `registryGroup` staying null there) before falling
// through to the existing per-registry path unchanged — the exact pattern
// OrgComposerEndpointTest.php pins for Composer (Task 3) and OrgNpmEndpointTest.php for npm
// (Task 4), copied here for the third ecosystem: the two German 403 messages, the
// 401-before-403-before-package-lookup ordering, and the "version_constraint is not enforced
// on any path" pin.
//
// One PyPI-specific case this file adds that neither sibling has: the upstream-redirect
// branch in simpleProject() is group-only (a Python upstream lives on ONE group's
// Upstream row; the org aggregate spans every group of the organization, each with its own
// or no upstream — there is no single "the upstream" to redirect to). An unknown project in
// org mode must answer a plain 404, never a redirect.
use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PythonDist;
use App\Models\RegistryToken;
use App\Models\Upstream;
use Illuminate\Support\Facades\Storage;

it('lists the union of projects assigned across every group of the organization, including a shared one', function () {
    $org = Organization::factory()->create();
    $groupA = Group::factory()->for($org)->create();
    $groupB = Group::factory()->for($org)->create();

    $pkgA = Package::factory()->inOrgOf($groupA)->create(['type' => PackageType::Python, 'name' => 'acme-a', 'repository_url' => null]);
    $groupA->packages()->attach($pkgA);
    $pkgB = Package::factory()->inOrgOf($groupB)->create(['type' => PackageType::Python, 'name' => 'acme-b', 'repository_url' => null]);
    $groupB->packages()->attach($pkgB);

    $operator = Organization::factory()->create(['is_operator' => true]);
    $shared = Package::factory()->for($operator)->create(['type' => PackageType::Python, 'name' => 'acme-shared', 'shared' => true, 'repository_url' => null]);
    $groupA->packages()->attach($shared);

    $res = $this->withHeaders(orgTokenHeaderFor($org))->get(orgRegistryPath($org).'/simple');

    $res->assertOk();
    $res->assertSee('acme-a');
    $res->assertSee('acme-b');
    $res->assertSee('acme-shared');
});

it('normalises project names per PEP 503 in the simple index, same as the group endpoint', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Python, 'name' => 'My.Package', 'repository_url' => null]);
    $group->packages()->attach($pkg);

    $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org).'/simple')
        ->assertOk()
        ->assertSee('my-package');
});

// Spec §5: a customer's own project always wins over a shared one of the same normalised
// name — the same rule SharedPackageResolutionTest.php pins for the group endpoint, and
// OrgComposerEndpointTest.php pins for the org Composer endpoint. The organization's own
// `shared-lib` (assigned to groupA) and an operator's SHARED `shared-lib` (assigned to
// groupB of the SAME organization) are both visible through organizationPackagesQuery() at
// once. The shared package is created and attached FIRST, so an unordered query would tend
// to return it instead.
it('serves the organization\'s own python project over a shared one of the same name, through the org endpoint', function () {
    $org = Organization::factory()->create();
    $groupA = Group::factory()->for($org)->create();
    $groupB = Group::factory()->for($org)->create();

    $operator = Organization::factory()->create(['is_operator' => true]);
    $shared = Package::factory()->for($operator)->create(['type' => PackageType::Python, 'name' => 'shared-lib', 'shared' => true, 'repository_url' => null]);
    $groupB->packages()->attach($shared);
    PythonDist::factory()->for($shared)->create(['version' => '1.0.0', 'filename' => 'shared_lib-1.0.0.tar.gz']);

    $own = Package::factory()->inOrgOf($groupA)->create(['type' => PackageType::Python, 'name' => 'shared-lib', 'repository_url' => null]);
    $groupA->packages()->attach($own);
    PythonDist::factory()->for($own)->create(['version' => '9.9.9', 'filename' => 'shared_lib-9.9.9.tar.gz']);

    $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org).'/simple/shared-lib/')
        ->assertOk()
        ->assertSee('shared_lib-9.9.9.tar.gz')
        ->assertDontSee('shared_lib-1.0.0.tar.gz');
});

it('answers a valid, empty simple index for an organization with zero visible projects', function () {
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create(); // a group exists, but nothing is assigned to it

    $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org).'/simple')
        ->assertOk();
});

it('serves the project page and a file download through the org endpoint', function () {
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Python, 'name' => 'acme-demo', 'repository_url' => null]);
    $group->packages()->attach($pkg);
    $dist = PythonDist::factory()->for($pkg)->create(['version' => '1.0.0', 'filename' => 'acme_demo-1.0.0.tar.gz']);
    Storage::disk('artifacts')->put($dist->path, 'sdist-bytes');

    $headers = orgTokenHeaderFor($org);

    $projectRes = $this->withHeaders($headers)->get(orgRegistryPath($org).'/simple/acme-demo/');
    $projectRes->assertOk()->assertSee('acme_demo-1.0.0.tar.gz');

    $this->withHeaders($headers)
        ->get(orgRegistryPath($org)."/pypi/files/{$pkg->id}/acme_demo-1.0.0.tar.gz")
        ->assertOk()
        ->assertHeader('content-type', 'application/octet-stream');
});

it('serves the PEP 691 JSON project page through the org endpoint', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Python, 'name' => 'acme-demo', 'repository_url' => null]);
    $group->packages()->attach($pkg);
    PythonDist::factory()->for($pkg)->create(['version' => '1.0.0', 'filename' => 'acme_demo-1.0.0.tar.gz']);

    $this->withHeaders(array_merge(orgTokenHeaderFor($org), ['Accept' => 'application/vnd.pypi.simple.v1+json']))
        ->get(orgRegistryPath($org).'/simple/acme-demo/')
        ->assertOk()
        ->assertJsonPath('name', 'acme-demo');
});

it('serves every version on /o/ regardless of version_constraint on the assignment, same as the group endpoint (unfiltered on every path today)', function () {
    // group_package.version_constraint is not enforced at serve time on ANY path — see
    // ComposerMetadataBuilder's docblock, and the same pin repeated in
    // OrgComposerEndpointTest/OrgNpmEndpointTest for their ecosystems. The spec's "union of
    // what any single group would serve" therefore reduces to "everything", because that is
    // what the (unfiltered) group endpoint already serves. Pinned here as an equality
    // against the group endpoint's own response for the same package, rather than against a
    // hardcoded version list, so this test breaks the day either path starts enforcing the
    // column without the other.
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['type' => PackageType::Python, 'name' => 'acme-lib', 'repository_url' => null]);
    PythonDist::factory()->for($pkg)->create(['version' => '1.0.0', 'filename' => 'acme_lib-1.0.0.tar.gz']);
    PythonDist::factory()->for($pkg)->create(['version' => '2.0.0', 'filename' => 'acme_lib-2.0.0.tar.gz']);
    // A constraint IS present on the assignment — proving it has no effect, not merely
    // absent from this scenario.
    $group->packages()->attach($pkg, ['version_constraint' => '^1.0']);

    $orgRes = $this->withHeaders(array_merge(orgTokenHeaderFor($org), ['Accept' => 'application/vnd.pypi.simple.v1+json']))
        ->get(orgRegistryPath($org).'/simple/acme-lib/');
    $groupRes = $this->withHeaders(array_merge(tokenHeaderFor($group), ['Accept' => 'application/vnd.pypi.simple.v1+json']))
        ->get(registryPath($group).'/simple/acme-lib/');

    $orgRes->assertOk();
    $groupRes->assertOk();
    $orgVersions = $orgRes->json('versions');
    $groupVersions = $groupRes->json('versions');
    sort($orgVersions);
    sort($groupVersions);

    expect($orgVersions)->toBe(['1.0.0', '2.0.0']);
    expect($orgVersions)->toBe($groupVersions);
});

it('answers 401 with the same challenge as the group endpoint for an anonymous request', function () {
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create();

    // getJson(), not get(): an unauthenticated plain get() renders the framework's HTML
    // error page, and TestResponse::json() throws trying to decode that as JSON — this is
    // pinning the CHALLENGE, not the simple index's own HTML rendering, so a JSON Accept
    // header is the right tool here, same as OrgComposerEndpointTest/OrgNpmEndpointTest.
    $orgRes = $this->getJson(orgRegistryPath($org).'/simple');
    $groupRes = $this->getJson(registryPath(Group::factory()->for(Organization::factory())->create()).'/simple');

    $orgRes->assertUnauthorized();
    $groupRes->assertUnauthorized();
    expect($orgRes->json('message'))->toBe($groupRes->json('message'));
    expect(array_keys($orgRes->headers->all()))->toEqual(array_keys($groupRes->headers->all()));
});

it('refuses a group-bound token of the same organization with the exact German message, before any project lookup', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $secret = Package::factory()->for($org)->create(['type' => PackageType::Python, 'name' => 'very-secret-name', 'repository_url' => null]);
    $group->packages()->attach($secret);

    [, $plain] = RegistryToken::issue($org, 'group-bound', $group);
    $headers = ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];

    $res = $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/simple/very-secret-name/');

    $res->assertForbidden();
    expect($res->json('message'))
        ->toBe('Dieses Token gilt nur für eine einzelne Registry — für die organisationsweite Quelle wird ein organisationsweites Token benötigt.');
    expect($res->getContent())->not->toContain('very-secret-name');
});

it('gives a denied caller the identical 403 for a nonexistent project name as for an existing one — no existence oracle', function () {
    // Same property, same reasoning, as OrgComposerEndpointTest's/OrgNpmEndpointTest's
    // identically-shaped case: a caller canAccessOrganization() refuses must not be able to
    // tell, from status or message, whether the project name they asked about exists. If
    // project resolution ever ran before authorization, a denied caller would get 404 for an
    // unregistered name but 403 for an existing-but-forbidden one — the split itself is the
    // leak.
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $secret = Package::factory()->for($org)->create(['type' => PackageType::Python, 'name' => 'very-secret-name', 'repository_url' => null]);
    $group->packages()->attach($secret);

    [, $plain] = RegistryToken::issue($org, 'group-bound', $group);
    $headers = ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];

    $existingRes = $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/simple/very-secret-name/');
    $missingRes = $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/simple/does-not-exist/');

    $existingRes->assertForbidden();
    $missingRes->assertForbidden();
    expect($missingRes->status())->toBe($existingRes->status());
    expect($missingRes->json('message'))->toBe($existingRes->json('message'));
    expect($existingRes->getContent())->not->toContain('very-secret-name');
    expect($missingRes->getContent())->not->toContain('does-not-exist');
});

it('refuses a foreign organization\'s org-wide token with the generic German message, before any project lookup', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $secret = Package::factory()->for($org)->create(['type' => PackageType::Python, 'name' => 'very-secret-name', 'repository_url' => null]);
    $group->packages()->attach($secret);

    $foreignOrg = Organization::factory()->create();

    $res = $this->withHeaders(orgTokenHeaderFor($foreignOrg))
        ->getJson(orgRegistryPath($org).'/simple/very-secret-name/');

    $res->assertForbidden();
    expect($res->json('message'))->toBe('Kein Zugriff auf diese Organisation.');
    expect($res->getContent())->not->toContain('very-secret-name');
});

it('refuses a foreign organization\'s group-bound token with the generic German message', function () {
    $org = Organization::factory()->create();
    $foreignGroup = Group::factory()->for(Organization::factory())->create();

    [, $plain] = RegistryToken::issue($foreignGroup->organization, 'foreign', $foreignGroup);
    $headers = ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];

    $res = $this->withHeaders($headers)->getJson(orgRegistryPath($org).'/simple');

    $res->assertForbidden();
    expect($res->json('message'))->toBe('Kein Zugriff auf diese Organisation.');
});

it('a publish-ability org token still only reads (access, not ability, gates the endpoint)', function () {
    // Not a brief case, but the same cheap insurance OrgComposerEndpointTest/
    // OrgNpmEndpointTest carry: canAccessOrganization() must not have grown a dependency on
    // ability while this task touched the surrounding controller code.
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->for($org)->create(['type' => PackageType::Python, 'name' => 'acme-demo', 'repository_url' => null]);
    $group->packages()->attach($pkg);

    [, $plain] = RegistryToken::issue($org, 'ci', null, TokenAbility::Publish);
    $headers = ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];

    $this->withHeaders($headers)->get(orgRegistryPath($org).'/simple/acme-demo/')->assertOk();
});

it('returns a plain 404 for an unknown project in org mode, WITHOUT redirecting to any group\'s upstream', function () {
    // The redirect branch in PypiController::simpleProject() is group-only: a Python
    // upstream is a row on ONE group, and the org aggregate spans every group of the
    // organization (each with its own, possibly different, possibly absent upstream) — there
    // is no single "the upstream" an org-mode miss could forward to. Configuring an upstream
    // on the org's own group here and still getting a 404 (not a redirect) is exactly what
    // pins that the branch is skipped, not merely untriggered because no upstream existed.
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    Upstream::factory()->for($group)->create(['type' => PackageType::Python, 'url' => 'https://pypi.org', 'enabled' => true]);

    $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org).'/simple/does-not-exist/')
        ->assertNotFound();
});

it('unknown org slug still 404s under the pypi simple-project path', function () {
    $this->get('/o/no-such-org/simple/does-not-exist/')->assertNotFound();
});

// download()'s org branch now resolves through a keyed SQL query (organizationPackagesQuery()
// narrowed by type and whereKey()) instead of loading every visible package as full models
// and filtering in PHP by `$p->id === $package`. That PHP comparison was case-sensitive,
// while Postgres' `uuid` column comparison (what whereKey() now runs) is not — and the route
// pattern for {package} admits uppercase hex (`[0-9a-fA-F]`). This pins that an uppercase-hex
// id resolves identically on /o/ and /r/, rather than 404ing on one and not the other.
it('resolves an uppercase-hex UUID download identically on the org and the group endpoint', function () {
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Python, 'name' => 'acme-demo', 'repository_url' => null]);
    $group->packages()->attach($pkg);
    $dist = PythonDist::factory()->for($pkg)->create(['version' => '1.0.0', 'filename' => 'acme_demo-1.0.0.tar.gz']);
    Storage::disk('artifacts')->put($dist->path, 'sdist-bytes');

    $uppercaseId = strtoupper($pkg->id);
    expect($uppercaseId)->not->toBe($pkg->id); // the case flip must actually change something

    $orgRes = $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org)."/pypi/files/{$uppercaseId}/acme_demo-1.0.0.tar.gz");
    $groupRes = $this->withHeaders(tokenHeaderFor($group))
        ->get(registryPath($group)."/pypi/files/{$uppercaseId}/acme_demo-1.0.0.tar.gz");

    $orgRes->assertOk();
    $groupRes->assertOk();
});

// The keyed query still has to carry the same "own organization, or shared" scope
// organizationPackagesQuery() states — narrowing to type/id must not accidentally widen
// past it. A package assigned only to a foreign organization's group must still 404 on the
// org download path.
it('404s a python download for a package outside the organization, through the org endpoint', function () {
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create();

    $foreignGroup = Group::factory()->create();
    $foreign = Package::factory()->inOrgOf($foreignGroup)->create(['type' => PackageType::Python, 'name' => 'internal-lib', 'repository_url' => null]);
    $foreignGroup->packages()->attach($foreign);
    $dist = PythonDist::factory()->for($foreign)->create(['version' => '1.0.0', 'filename' => 'internal_lib-1.0.0.tar.gz']);
    Storage::disk('artifacts')->put($dist->path, 'sdist-bytes');

    $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org)."/pypi/files/{$foreign->id}/internal_lib-1.0.0.tar.gz")
        ->assertNotFound();
});

it('denies a file download for a project not visible to the organization', function () {
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create();
    $pkg = Package::factory()->create(['type' => PackageType::Python, 'name' => 'acme-demo', 'repository_url' => null]); // different org, not shared
    $dist = PythonDist::factory()->for($pkg)->create(['version' => '1.0.0', 'filename' => 'acme_demo-1.0.0.tar.gz']);
    Storage::disk('artifacts')->put($dist->path, 'sdist-bytes');

    $this->withHeaders(orgTokenHeaderFor($org))
        ->get(orgRegistryPath($org)."/pypi/files/{$pkg->id}/acme_demo-1.0.0.tar.gz")
        ->assertNotFound();
});
