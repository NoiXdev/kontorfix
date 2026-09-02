<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function runAddOrganizationIdToPackagesMigration(): object
{
    return require database_path('migrations/2026_09_02_100000_add_organization_id_to_packages.php');
}

it('backfills a package owner from the registry it is attached to', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->for($org)->create();
    $group->packages()->attach($package);

    // Simulate the pre-migration schema: this row predates the `organization_id` column.
    Schema::table('packages', fn (Blueprint $table) => $table->dropColumn('organization_id'));

    runAddOrganizationIdToPackagesMigration()->up();

    expect(DB::table('packages')->where('id', $package->id)->value('organization_id'))->toBe($org->id);
});
