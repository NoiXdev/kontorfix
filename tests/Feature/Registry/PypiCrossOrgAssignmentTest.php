<?php

// The PyPI read paths resolve through $group->packages(), and the pivot row records
// assignment while canAccessPackage() checks assignment and group access — neither compares
// the package's organization to the registry's. A pivot row pointing into a foreign tenant
// therefore used to be served here while Composer and npm refused it.
//
// The enforcement migration now refuses to complete while such a row exists, so on a healthy
// instance none of these fixtures can occur. These tests hold the second line: the read paths
// state the ownership constraint themselves rather than inheriting it from the access check.
use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Package;
use App\Models\PythonDist;
use Illuminate\Support\Facades\Storage;

/**
 * A package owned by one organization, attached to another organization's registry by a
 * pre-invariant pivot row — exactly what the migration now refuses.
 *
 * @return array{0: Group, 1: Package}
 */
function crossOrgPythonAssignment(): array
{
    $mine = Group::factory()->create(['slug' => 'mine', 'public' => true]);
    $theirs = Group::factory()->create(['slug' => 'theirs', 'public' => true]);

    $foreign = Package::factory()->inOrgOf($theirs)->create([
        'type' => PackageType::Python, 'name' => 'internal-lib', 'repository_url' => null,
    ]);
    $mine->packages()->attach($foreign);

    return [$mine, $foreign];
}

it('does not list another organization\'s package in the simple index', function () {
    [$mine] = crossOrgPythonAssignment();

    $this->get("/r/{$mine->slug}/simple")
        ->assertOk()
        ->assertDontSee('internal-lib');
});

it('does not serve another organization\'s project page', function () {
    [$mine] = crossOrgPythonAssignment();

    // No upstream configured, so the refusal is a flat 404 rather than a fallthrough.
    $this->get("/r/{$mine->slug}/simple/internal-lib/")->assertNotFound();
});

it('does not stream a distribution of another organization\'s package', function () {
    Storage::fake('artifacts');
    [$mine, $foreign] = crossOrgPythonAssignment();

    $dist = PythonDist::factory()->for($foreign)->create([
        'version' => '1.0.0', 'filename' => 'internal_lib-1.0.0.tar.gz',
    ]);
    // The file really is on disk: without it the route 404s on the missing artifact and
    // this test would pass whatever the ownership check does.
    Storage::disk('artifacts')->put($dist->path, 'sdist-bytes');

    $this->get("/r/{$mine->slug}/pypi/files/{$foreign->id}/internal_lib-1.0.0.tar.gz")
        ->assertNotFound();
});
