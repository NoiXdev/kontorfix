<?php

// A shared package is owned by the operator organization (spec §1), so every read path
// v0.8.0 scoped to the addressed registry's organization refused it. These tests state what
// the widened predicate must and must not do: a shared package resolves from a registry it
// is assigned to, and from nowhere else — sharing grants eligibility, not access.
use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PythonDist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A shared package, owned by an operator organization and assigned to `$group`.
 *
 * The assignment is made with a bare `attach()` rather than through the admin controllers:
 * this file is about what the read paths do with a pivot row, not about how one gets
 * written. `packages()` rather than `assignedPackages()` for the same reason — the latter
 * is a read filter and has no attach side.
 */
function sharedPackageIn(Group $group, PackageType $type = PackageType::Composer, string $name = 'acme/shared'): Package
{
    $operator = Organization::factory()->create(['is_operator' => true]);
    $package = Package::factory()->for($operator)->create([
        'type' => $type, 'name' => $name, 'shared' => true,
    ]);
    $group->packages()->attach($package);

    return $package;
}

it('serves a shared package assigned to the registry', function () {
    $group = Group::factory()->create(['public' => true]);
    sharedPackageIn($group);

    $this->get(registryPath($group).'/p2/acme/shared.json')->assertOk();
});

it('does not serve a shared package that is not assigned to this registry', function () {
    $group = Group::factory()->create(['public' => true]);
    sharedPackageIn(Group::factory()->create(['public' => true]));

    // Sharing grants eligibility, not access.
    $this->get(registryPath($group).'/p2/acme/shared.json')->assertNotFound();
});

it('lists a shared package in the composer index', function () {
    $group = Group::factory()->create(['public' => true]);
    sharedPackageIn($group);

    $this->get(registryPath($group).'/packages.json')
        ->assertOk()
        ->assertJsonPath('available-packages', ['acme/shared']);
});

it('serves a shared npm package assigned to the registry', function () {
    $group = Group::factory()->create(['public' => true]);
    sharedPackageIn($group, PackageType::Npm, 'shared-lib');

    $this->get(registryPath($group).'/shared-lib')
        ->assertOk()
        ->assertJsonPath('name', 'shared-lib');
});

it('lists a shared python project in the simple index', function () {
    $group = Group::factory()->create(['public' => true]);
    sharedPackageIn($group, PackageType::Python, 'shared-lib');

    $this->get(registryPath($group).'/simple')
        ->assertOk()
        ->assertSee('shared-lib');
});

it('serves the project page of a shared python package', function () {
    $group = Group::factory()->create(['public' => true]);
    $shared = sharedPackageIn($group, PackageType::Python, 'shared-lib');
    PythonDist::factory()->for($shared)->create([
        'version' => '1.0.0', 'filename' => 'shared_lib-1.0.0.tar.gz',
    ]);

    // No upstream configured, so a refusal here would be a flat 404 rather than a redirect.
    $this->get(registryPath($group).'/simple/shared-lib/')
        ->assertOk()
        ->assertSee('shared_lib-1.0.0.tar.gz');
});

it('streams a distribution of a shared python package', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->create(['public' => true]);
    $shared = sharedPackageIn($group, PackageType::Python, 'shared-lib');
    $dist = PythonDist::factory()->for($shared)->create([
        'version' => '1.0.0', 'filename' => 'shared_lib-1.0.0.tar.gz',
    ]);
    // The file really is on disk: without it the route 404s on the missing artifact and this
    // test would pass whatever the ownership predicate does.
    Storage::disk('artifacts')->put($dist->path, 'sdist-bytes');

    $this->get(registryPath($group)."/pypi/files/{$shared->id}/shared_lib-1.0.0.tar.gz")->assertOk();
});

// Sharing is read access. A customer's publish token must never be able to add a version to
// the operator's shared package, because every other customer's builds resolve that package.
// Both write paths therefore stay scoped to the addressed registry's own organization.

it('refuses a twine upload into a shared package', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->create(['public' => true]);
    sharedPackageIn($group, PackageType::Python, 'shared-lib');
    $bytes = 'fake-sdist-content';

    $this->withHeaders(publishHeaderFor($group))->post(registryPath($group).'/', [
        ':action' => 'file_upload',
        'name' => 'shared-lib',
        'version' => '1.0.0',
        'filetype' => 'sdist',
        'sha256_digest' => hash('sha256', $bytes),
        'content' => UploadedFile::fake()->createWithContent('shared_lib-1.0.0.tar.gz', $bytes),
    ])->assertNotFound();
});

it('allows the operator to publish into its own shared project from an operator registry', function () {
    Storage::fake('artifacts');
    $operator = Organization::factory()->create(['is_operator' => true]);
    $group = Group::factory()->for($operator)->create(['public' => true]);
    $pkg = Package::factory()->for($operator)->create([
        'type' => PackageType::Python, 'name' => 'shared-lib', 'shared' => true,
    ]);
    $group->packages()->attach($pkg);
    $bytes = 'fake-sdist-content';

    // Bounds the refusal above from the other side. `! $p->shared` would satisfy that test
    // just as well as the ownership comparison does, and would take the operator's own shared
    // project away from the operator: marking a package shared would silently make it
    // unpublishable from the registry that owns it. The rule is ownership, not the flag.
    $this->withHeaders(publishHeaderFor($group))->post(registryPath($group).'/', [
        ':action' => 'file_upload',
        'name' => 'shared-lib',
        'version' => '1.0.0',
        'filetype' => 'sdist',
        'sha256_digest' => hash('sha256', $bytes),
        'content' => UploadedFile::fake()->createWithContent('shared_lib-1.0.0.tar.gz', $bytes),
    ])->assertOk();

    expect($pkg->fresh()->pythonDists()->count())->toBe(1);
});

it('refuses a twine upload into a project whose assignment has lapsed', function () {
    Storage::fake('artifacts');
    $group = Group::factory()->create(['public' => true]);
    $own = Package::factory()->inOrgOf($group)->create([
        'type' => PackageType::Python, 'name' => 'own-lib',
    ]);
    // upload() resolves its target through the assignment relation and through nothing else —
    // it never reaches canAccessPackage() — so this relation is the only thing that can make
    // a lapsed assignment refuse a publish. npm has always refused it, through
    // packageBelongsToGroup(); the two publish paths must not disagree.
    $group->packages()->attach($own, ['available_until' => now()->subDay()]);
    $bytes = 'fake-sdist-content';

    $this->withHeaders(publishHeaderFor($group))->post(registryPath($group).'/', [
        ':action' => 'file_upload',
        'name' => 'own-lib',
        'version' => '1.0.0',
        'filetype' => 'sdist',
        'sha256_digest' => hash('sha256', $bytes),
        'content' => UploadedFile::fake()->createWithContent('own_lib-1.0.0.tar.gz', $bytes),
    ])->assertNotFound();
});

it('refuses an npm publish into a shared package', function () {
    $group = Group::factory()->create(['public' => true]);
    sharedPackageIn($group, PackageType::Npm, 'shared-lib');

    $this->withHeaders(publishHeaderFor($group))
        ->putJson(registryPath($group).'/shared-lib', publishBody('shared-lib', '1.0.0', 'shared-lib-1.0.0.tgz', 'tgz-bytes'))
        ->assertNotFound();
});

// Spec §5: a customer's own package always wins over a shared one of the same name.
//
// App\Services\Package\SharedAssignment refuses that assignment on every write path the
// application offers, so this state cannot be reached through the product — these fixtures
// attach both pivot rows directly, which is the one way left to produce it (a direct
// database change, or a data migration). The resolution order still has to be *decided*:
// `first()` over two candidates without an ORDER BY returns whichever row the database
// hands back, and "usually the right one" is not an answer. The shared package is created
// and attached FIRST in each of these, so an unordered query would tend to return it.

it('serves a shared package when the customer owns that name but has not assigned it here', function () {
    $group = Group::factory()->create(['public' => true]);
    $shared = sharedPackageIn($group);
    PackageVersion::factory()->for($shared)->create(['version' => '1.0.0.0', 'version_pretty' => 'v1.0.0']);

    // The customer owns the same name and keeps it in a different registry of their own.
    // SharedAssignment permits this and is right to: it refuses a collision within one
    // registry, and this registry serves only the shared package. So unlike the pair below,
    // this state is reachable through the product with no database surgery at all.
    //
    // The own row sorts first — it is the customer's own organization — but it is not
    // assigned here. Choosing the candidate before asking about assignment would therefore
    // answer with the own row, fail the assignment check, and 404 a package the operator
    // explicitly assigned, on every Composer and npm read path at once.
    $elsewhere = Group::factory()->create(['organization_id' => $group->organization_id, 'public' => true]);
    $own = Package::factory()->inOrgOf($group)->create([
        'type' => PackageType::Composer, 'name' => 'acme/shared',
    ]);
    PackageVersion::factory()->for($own)->create(['version' => '9.9.9.0', 'version_pretty' => 'v9.9.9']);
    $elsewhere->packages()->attach($own);

    $this->get(registryPath($group).'/p2/acme/shared.json')
        ->assertOk()
        ->assertJsonPath('packages.acme/shared.0.version', 'v1.0.0');
});

it('serves the customer own composer package over a shared one of the same name', function () {
    $group = Group::factory()->create(['public' => true]);
    $shared = sharedPackageIn($group);
    PackageVersion::factory()->for($shared)->create(['version' => '1.0.0.0', 'version_pretty' => 'v1.0.0']);

    $own = Package::factory()->inOrgOf($group)->create([
        'type' => PackageType::Composer, 'name' => 'acme/shared',
    ]);
    PackageVersion::factory()->for($own)->create(['version' => '9.9.9.0', 'version_pretty' => 'v9.9.9']);
    $group->packages()->attach($own);

    $this->get(registryPath($group).'/p2/acme/shared.json')
        ->assertOk()
        ->assertJsonPath('packages.acme/shared.0.version', 'v9.9.9');

    // Composer reads `available-packages` as the set of names this registry hosts, and two
    // assigned rows of one name must still be one entry.
    $this->get(registryPath($group).'/packages.json')
        ->assertOk()
        ->assertJsonPath('available-packages', ['acme/shared']);
});

it('serves the customer own python project over a shared one of the same name', function () {
    $group = Group::factory()->create(['public' => true]);
    $shared = sharedPackageIn($group, PackageType::Python, 'shared-lib');
    PythonDist::factory()->for($shared)->create([
        'version' => '1.0.0', 'filename' => 'shared_lib-1.0.0.tar.gz',
    ]);

    $own = Package::factory()->inOrgOf($group)->create([
        'type' => PackageType::Python, 'name' => 'shared-lib',
    ]);
    PythonDist::factory()->for($own)->create([
        'version' => '9.9.9', 'filename' => 'shared_lib-9.9.9.tar.gz',
    ]);
    $group->packages()->attach($own);

    $this->get(registryPath($group).'/simple/shared-lib/')
        ->assertOk()
        ->assertSee('shared_lib-9.9.9.tar.gz')
        ->assertDontSee('shared_lib-1.0.0.tar.gz');

    // And the root index names it once, not once per assigned row.
    $index = $this->get(registryPath($group).'/simple')->assertOk()->getContent();
    expect(substr_count((string) $index, '>shared-lib</a>'))->toBe(1);
});
