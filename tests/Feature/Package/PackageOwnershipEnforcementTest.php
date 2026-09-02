<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function runEnforcePackageOrganizationMigration(): object
{
    return require database_path('migrations/2026_09_02_110000_enforce_package_organization.php');
}

it('refuses to enforce ownership while a package has no registry', function () {
    // RefreshDatabase has already run every migration by the time this test executes, so
    // organization_id is already NOT NULL. Relax it first so an ownerless row can exist to
    // exercise the refusal — the migration under test checks this before it touches the
    // schema, so up() never reaches the (currently absent) nullable-column constraint.
    Schema::table('packages', fn (Blueprint $table) => $table->uuid('organization_id')->nullable()->change());

    $package = Package::factory()->create();
    DB::table('packages')->where('id', $package->id)->update(['organization_id' => null]);

    expect(fn () => runEnforcePackageOrganizationMigration()->up())
        ->toThrow(RuntimeException::class, $package->name);
});

it('lets two organizations hold the same package name', function () {
    $a = Group::factory()->create();
    $b = Group::factory()->create();

    Package::factory()->inOrgOf($a)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $second = Package::factory()->inOrgOf($b)->create(['type' => 'composer', 'name' => 'acme/tools']);

    expect($second->exists)->toBeTrue()
        ->and($a->organization_id)->not->toBe($b->organization_id);
});

it('still refuses the same name twice inside one organization', function () {
    $org = Organization::factory()->create();
    Package::factory()->for($org)->create(['type' => 'composer', 'name' => 'acme/tools']);

    expect(fn () => Package::factory()->for($org)->create(['type' => 'composer', 'name' => 'acme/tools']))
        ->toThrow(UniqueConstraintViolationException::class);
});
