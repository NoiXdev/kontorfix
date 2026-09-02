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

it('refuses to enforce ownership while a package is attached to a foreign registry', function () {
    // The backfill takes the OLDEST registry's organization as the owner and leaves every
    // other assignment in place, so a package shared across organizations before this
    // release keeps a pivot row pointing into a tenant that no longer owns it. Refused and
    // named rather than deleted: these are somebody's deliberate registry assignments.
    $mine = Group::factory()->create(['slug' => 'mine']);
    $theirs = Group::factory()->create(['slug' => 'theirs']);

    $foreign = Package::factory()->inOrgOf($theirs)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $mine->packages()->attach($foreign);

    // Both the package and the registry it wrongly hangs off have to be named, or the
    // operator cannot act on the message.
    expect(fn () => runEnforcePackageOrganizationMigration()->up())
        ->toThrow(RuntimeException::class, 'acme/tools')
        ->and(fn () => runEnforcePackageOrganizationMigration()->up())
        ->toThrow(RuntimeException::class, 'mine');
});

it('lets a package be attached to several registries of its own organization', function () {
    // The counterpart: multi-registry assignment stays legal inside one organization, so
    // the refusal above cannot be satisfied by banning shared packages outright.
    $one = Group::factory()->create();
    $two = Group::factory()->create(['organization_id' => $one->organization_id]);

    $package = Package::factory()->inOrgOf($one)->create(['type' => 'composer', 'name' => 'acme/shared']);
    $one->packages()->attach($package);
    $two->packages()->attach($package);

    $error = null;
    try {
        runEnforcePackageOrganizationMigration()->up();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    // up() cannot run to completion a second time: RefreshDatabase has already applied it,
    // so the schema block trips over the unique index it has already replaced. Getting that
    // far is the assertion — the cross-organization refusal did not fire on these rows.
    expect($error)->not->toContain('attached to a registry of another');
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

it('reports the org-less registry, not the package, when both hold', function () {
    // The backfill deliberately skips registries with a null organization, so a package
    // attached ONLY to such a registry comes out ownerless too. Both refusals therefore
    // fire on the same data, and the order decides what the operator is told. Registry
    // ownership is the precondition, so it has to be reported first: the ownerless message
    // ("belongs to no registry ... attach each to a registry, or delete it") is false here,
    // and following it either works by accident or destroys data for no reason.
    Schema::table('groups', fn (Blueprint $table) => $table->uuid('organization_id')->nullable()->change());
    Schema::table('packages', fn (Blueprint $table) => $table->uuid('organization_id')->nullable()->change());

    $group = Group::factory()->create(['slug' => 'legacy-registry']);
    $package = Package::factory()->create(['type' => 'composer', 'name' => 'acme/stranded']);
    $group->packages()->attach($package);

    DB::table('groups')->where('id', $group->id)->update(['organization_id' => null]);
    DB::table('packages')->where('id', $package->id)->update(['organization_id' => null]);

    $message = null;
    try {
        runEnforcePackageOrganizationMigration()->up();
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('these registries belong to no organization')
        ->and($message)->toContain('legacy-registry')
        ->and($message)->not->toContain('belong to no registry');
});

it('refuses to roll back while two organizations hold the same package name', function () {
    // down() restores the old global unique(type, name), which cannot hold once two
    // organizations legitimately share a name — the whole point of up(). Refused with the
    // pairs named, rather than a bare unique violation part-way through the rollback.
    $a = Group::factory()->create();
    $b = Group::factory()->create();

    Package::factory()->inOrgOf($a)->create(['type' => 'composer', 'name' => 'acme/shared-name']);
    Package::factory()->inOrgOf($b)->create(['type' => 'composer', 'name' => 'acme/shared-name']);

    // Asserted on the message, not on RuntimeException: Illuminate's QueryException extends
    // PDOException extends RuntimeException, so a bare unique violation mid-rollback — the
    // exact failure this refusal exists to prevent — would satisfy a class-only assertion.
    $message = null;
    try {
        runEnforcePackageOrganizationMigration()->down();
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('Cannot roll back package ownership enforcement')
        ->and($message)->toContain('acme/shared-name');

    // The refusal happens before any schema change, so the org-scoped index is still there.
    expect(fn () => Package::factory()->inOrgOf($a)->create(['type' => 'composer', 'name' => 'acme/shared-name']))
        ->toThrow(UniqueConstraintViolationException::class);
});
