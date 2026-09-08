<?php

use App\Models\GitCredential;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RetentionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('stores inline rules on a package and ships them null', function () {
    $package = Package::factory()->docker()->create();

    expect($package->retention_rules)->toBeNull();

    $package->update(['retention_rules' => [['type' => 'keep_last', 'count' => 5]]]);

    // jsonb round-trips as a PHP array, not a string.
    expect($package->fresh()->retention_rules)->toBe([['type' => 'keep_last', 'count' => 5]]);
});

it('marks a policy as global, off by default', function () {
    expect(RetentionPolicy::factory()->create()->is_global)->toBeFalse();

    $global = RetentionPolicy::factory()->create(['is_global' => true]);

    expect($global->fresh()->is_global)->toBeTrue();
});

it('marks a git credential as global, off by default', function () {
    expect(GitCredential::factory()->create()->is_global)->toBeFalse();
});

it('shares a git credential to selected organizations exactly once each', function () {
    $credential = GitCredential::factory()->create();
    $target = Organization::factory()->create();

    $credential->sharedOrganizations()->attach($target);

    expect($credential->sharedOrganizations()->pluck('organizations.id')->all())->toBe([$target->id]);

    // The pivot is unique: sharing twice is a caller bug, not a second grant.
    expect(fn () => $credential->sharedOrganizations()->attach($target))
        ->toThrow(QueryException::class);
});

it('answers usableBy for owner, global, shared and stranger', function () {
    $owner = Organization::factory()->create();
    $stranger = Organization::factory()->create();
    $sharedTo = Organization::factory()->create();

    $own = GitCredential::factory()->for($owner)->create();
    $global = GitCredential::factory()->create(['is_global' => true]);
    $shared = GitCredential::factory()->create();
    $shared->sharedOrganizations()->attach($sharedTo);

    // The instance method and the query scope answer identically — both are asserted so
    // neither can drift: the dropdown reads the scope, the sync-time check the method.
    expect($own->isUsableBy($owner))->toBeTrue()
        ->and($own->isUsableBy($stranger))->toBeFalse()
        ->and($global->isUsableBy($stranger))->toBeTrue()
        ->and($shared->isUsableBy($sharedTo))->toBeTrue()
        ->and($shared->isUsableBy($stranger))->toBeFalse();

    expect(GitCredential::usableBy($owner)->pluck('id'))->toContain($own->id)
        ->and(GitCredential::usableBy($stranger)->pluck('id')->all())->toBe([$global->id])
        ->and(GitCredential::usableBy($sharedTo)->pluck('id')->sort()->values())
        ->toHaveCount(2);
});

it('drops the share when either side is deleted', function () {
    $credential = GitCredential::factory()->create();
    $target = Organization::factory()->create();
    $credential->sharedOrganizations()->attach($target);

    $credential->delete();

    expect(DB::table('git_credential_organization')->count())->toBe(0);
});

it('has the new columns', function () {
    expect(Schema::hasColumn('packages', 'retention_rules'))->toBeTrue()
        ->and(Schema::hasColumn('retention_policies', 'is_global'))->toBeTrue()
        ->and(Schema::hasColumn('git_credentials', 'is_global'))->toBeTrue()
        ->and(Schema::hasTable('git_credential_organization'))->toBeTrue();
});

/** Same shape as GroupSlugUniquenessMigrationTest's runScopeGroupSlugMigration(). */
function runAddRetentionScopesMigration(): object
{
    return require database_path('migrations/2026_09_08_200000_add_retention_scopes.php');
}

it('rolls back the pivot before the columns it references, cleanly', function () {
    // RefreshDatabase has already run up() for this test; populate every row down() has to
    // get rid of first, so a wrong drop order (a column dropped while the pivot referencing
    // its table still holds a row) would surface here rather than only on a bare schema.
    $package = Package::factory()->docker()->create(['retention_rules' => [['type' => 'keep_last', 'count' => 5]]]);
    $policy = RetentionPolicy::factory()->create(['is_global' => true]);
    $credential = GitCredential::factory()->create(['is_global' => true]);
    $organization = Organization::factory()->create();
    $credential->sharedOrganizations()->attach($organization);

    runAddRetentionScopesMigration()->down();

    // The pivot table is dropped outright (not row-by-row), so its FK to git_credentials
    // and organizations cannot be what blocks the column drops that follow — this is the
    // property "pivot before columns" buys, exercised rather than merely read off the
    // migration's source.
    expect(Schema::hasTable('git_credential_organization'))->toBeFalse()
        ->and(Schema::hasColumn('git_credentials', 'is_global'))->toBeFalse()
        ->and(Schema::hasColumn('retention_policies', 'is_global'))->toBeFalse()
        ->and(Schema::hasColumn('packages', 'retention_rules'))->toBeFalse();

    // The package and policy rows themselves survive — only the columns this migration
    // added are gone, not the tables.
    expect(Package::query()->whereKey($package->id)->exists())->toBeTrue()
        ->and(RetentionPolicy::query()->whereKey($policy->id)->exists())->toBeTrue();

    // up() re-applies cleanly afterward: a failed deploy that rolls back and retries is not
    // left with a schema neither migration recognises.
    runAddRetentionScopesMigration()->up();

    expect(Schema::hasColumn('packages', 'retention_rules'))->toBeTrue()
        ->and(Schema::hasColumn('retention_policies', 'is_global'))->toBeTrue()
        ->and(Schema::hasColumn('git_credentials', 'is_global'))->toBeTrue()
        ->and(Schema::hasTable('git_credential_organization'))->toBeTrue();
});
