<?php

// The Composer counterpart of PypiCrossOrgAssignmentTest. RegistryAccessService::
// availablePackages() resolved through a bare $group->packages(): the pivot row records
// assignment, and canAccessPackage() checks assignment and group access — neither compared
// the package's organization to the registry's. A registry with no Composer upstream
// therefore listed a foreign tenant's package *name* in `available-packages`. Name
// disclosure, not content — findLocal() still refused to serve the metadata — but the same
// severity class as the PyPI leak, one ecosystem over.
//
// availablePackages() also feeds packageBelongsToGroup() (the npm publish membership check)
// and canAccessPackage() (every ecosystem's access check), so the constraint is asserted at
// the service level too.
use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Package;
use App\Services\RegistryAccessService;

/**
 * A package owned by one organization, attached to another organization's registry by a
 * pre-invariant pivot row — exactly what the enforcement migration now refuses.
 *
 * @return array{0: Group, 1: Package}
 */
function crossOrgComposerAssignment(): array
{
    $mine = Group::factory()->create(['slug' => 'mine', 'public' => true]);
    $theirs = Group::factory()->create(['slug' => 'theirs', 'public' => true]);

    $foreign = Package::factory()->inOrgOf($theirs)->create([
        'type' => PackageType::Composer, 'name' => 'acme/internal-lib', 'repository_url' => null,
    ]);
    $mine->packages()->attach($foreign);

    return [$mine, $foreign];
}

it('does not list another organization\'s package in available-packages', function () {
    [$mine] = crossOrgComposerAssignment();

    // No Composer upstream on the registry, so root() serves the available-packages list
    // rather than the metadata-url-only document.
    $root = $this->getJson("/r/{$mine->slug}/packages.json")->assertOk()->json();

    expect($root)->toHaveKey('available-packages')
        ->and($root['available-packages'])->not->toContain('acme/internal-lib');
});

it('does not treat another organization\'s package as available to the registry', function () {
    [$mine, $foreign] = crossOrgComposerAssignment();

    $access = app(RegistryAccessService::class);

    // packageBelongsToGroup() is the npm publish membership check; canAccessPackage() gates
    // every ecosystem's reads. Both resolve through availablePackages().
    expect($access->packageBelongsToGroup($mine, $foreign))->toBeFalse()
        ->and($access->packagesFor($mine)->pluck('id')->all())->not->toContain($foreign->id)
        ->and($access->canAccessPackage(null, $mine, $foreign))->toBeFalse();
});

it('still serves a package owned by the registry\'s own organization', function () {
    // The counterpart: the new constraint must not hide a legitimately owned package.
    $mine = Group::factory()->create(['slug' => 'mine', 'public' => true]);
    $own = Package::factory()->inOrgOf($mine)->create([
        'type' => PackageType::Composer, 'name' => 'acme/own-lib', 'repository_url' => null,
    ]);
    $mine->packages()->attach($own);

    $root = $this->getJson("/r/{$mine->slug}/packages.json")->assertOk()->json();

    expect($root['available-packages'])->toContain('acme/own-lib')
        ->and(app(RegistryAccessService::class)->packageBelongsToGroup($mine, $own))->toBeTrue();
});
