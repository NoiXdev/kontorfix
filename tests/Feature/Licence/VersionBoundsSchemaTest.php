<?php

use App\Models\Group;
use App\Models\Package;
use App\Support\Licence\VersionBounds;
use App\Support\Licence\VersionWindows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has the new nullable columns', function () {
    expect(Schema::hasColumn('group_package', 'version_min'))->toBeTrue()
        ->and(Schema::hasColumn('group_package', 'version_max'))->toBeTrue();
});

it('reads back null/null for an existing assignment created without them', function () {
    $group = Group::factory()->create();
    $package = Package::factory()->docker()->create(['organization_id' => $group->organization_id]);

    $group->packages()->attach($package);

    $pivot = $group->packages()->first()->pivot;

    expect($pivot->version_min)->toBeNull()
        ->and($pivot->version_max)->toBeNull();
});

it('exposes version_min and version_max on all three pivot relations', function () {
    $group = Group::factory()->create();
    $package = Package::factory()->docker()->create(['organization_id' => $group->organization_id]);

    $group->packages()->attach($package, ['version_min' => '1.0', 'version_max' => '2.0']);

    $fromGroupPackages = $group->packages()->first()->pivot;
    $fromAssignedPackages = $group->assignedPackages()->first()->pivot;
    $fromPackageGroups = $package->groups()->first()->pivot;

    expect($fromGroupPackages->version_min)->toBe('1.0')
        ->and($fromGroupPackages->version_max)->toBe('2.0')
        ->and($fromAssignedPackages->version_min)->toBe('1.0')
        ->and($fromAssignedPackages->version_max)->toBe('2.0')
        ->and($fromPackageGroups->version_min)->toBe('1.0')
        ->and($fromPackageGroups->version_max)->toBe('2.0');
});

it('treats VersionBounds::fromPivot(null, null) as unlimited', function () {
    expect(VersionBounds::fromPivot(null, null)->isUnlimited())->toBeTrue();
});

it('treats VersionBounds::fromPivot with a bound as not unlimited', function () {
    expect(VersionBounds::fromPivot('2.0', null)->isUnlimited())->toBeFalse();
});

it('exposes VersionBounds::unlimited() as unlimited with both null', function () {
    $unlimited = VersionBounds::unlimited();

    expect($unlimited->isUnlimited())->toBeTrue()
        ->and($unlimited->min)->toBeNull()
        ->and($unlimited->max)->toBeNull();
});

// An empty window list means no unexpired assignment was found to build a window from —
// e.g. an assignment that lapsed or was revoked between two separate `now()` reads on the
// serving path. That must refuse every version, never admit everything: "unlimited" is
// represented only by an explicit VersionBounds::unlimited() MEMBER of the list (see the
// two tests below), never by the list being empty.
it('treats an empty VersionWindows as refusing every version, not as unlimited', function () {
    expect((new VersionWindows([]))->isUnlimited())->toBeFalse();
});

it('treats a VersionWindows with an unlimited member as unlimited', function () {
    $windows = new VersionWindows([
        VersionBounds::fromPivot('1.0', '1.9'),
        VersionBounds::unlimited(),
    ]);

    expect($windows->isUnlimited())->toBeTrue();
});

it('treats a VersionWindows with only bounded members as not unlimited', function () {
    $windows = new VersionWindows([
        VersionBounds::fromPivot('1.0', '1.9'),
        VersionBounds::fromPivot('2.0', '2.9'),
    ]);

    expect($windows->isUnlimited())->toBeFalse();
});

it('exposes VersionWindows::unlimited() as an unlimited instance', function () {
    expect(VersionWindows::unlimited()->isUnlimited())->toBeTrue();
});

/** Same shape as GroupSlugUniquenessMigrationTest's runScopeGroupSlugMigration(). */
function runAddVersionBoundsMigration(): object
{
    return require database_path('migrations/2026_09_11_100000_add_version_bounds_to_group_package.php');
}

it('rolls back cleanly and leaves group_package rows intact', function () {
    // RefreshDatabase has already run up() for this test; populate a row down() must not
    // touch, so a destructive drop (dropping the pivot table itself, or cascading into its
    // rows) would surface here rather than only on a bare schema.
    $group = Group::factory()->create();
    $package = Package::factory()->docker()->create(['organization_id' => $group->organization_id]);
    $group->packages()->attach($package, ['version_min' => '1.0', 'version_max' => '2.0']);

    runAddVersionBoundsMigration()->down();

    expect(Schema::hasColumn('group_package', 'version_min'))->toBeFalse()
        ->and(Schema::hasColumn('group_package', 'version_max'))->toBeFalse();

    // The assignment row itself survives — only the columns this migration added are gone.
    expect(DB::table('group_package')
        ->where('group_id', $group->id)
        ->where('package_id', $package->id)
        ->exists())->toBeTrue();

    // up() re-applies cleanly afterward: a failed deploy that rolls back and retries is not
    // left with a schema neither migration recognises.
    runAddVersionBoundsMigration()->up();

    expect(Schema::hasColumn('group_package', 'version_min'))->toBeTrue()
        ->and(Schema::hasColumn('group_package', 'version_max'))->toBeTrue();
});
