<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Enums\UserRole;
use App\Jobs\SyncPackage;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * A super-admin-equivalent operator: Admin of the operator organization. Named
 * differently from PythonPackageCreateTest's file-local `operatorAdmin()` (and from
 * PackageAbandonmentUpdateTest's `abandonmentOperator()`) — all three files load in the
 * same test process, and a same-named top-level function would fatal on redeclaration.
 */
function resyncOperator(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('queues a sync for a git-sourced package', function () {
    Queue::fake();
    $package = Package::factory()->create(['type' => PackageType::Composer, 'source_mode' => PackageSourceMode::Git]);

    $this->actingAs(resyncOperator())
        ->post(route('admin.packages.resync', $package))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        // Show.vue reads flash.success to render the toast the operator relies on
        // instead of clicking the button again — see Show.vue's flashSuccess computed.
        ->assertSessionHas('success', 'Synchronisierung wurde eingereiht.');

    Queue::assertPushed(SyncPackage::class, fn (SyncPackage $job): bool => $job->package->is($package));
});

it('refuses a publish-based package and queues nothing', function () {
    Queue::fake();
    $package = Package::factory()->create(['type' => PackageType::Npm, 'source_mode' => PackageSourceMode::Publish]);

    $this->actingAs(resyncOperator())
        ->post(route('admin.packages.resync', $package))
        ->assertStatus(409);

    Queue::assertNothingPushed();
});

it('forbids resyncing a package outside the administered org', function () {
    Queue::fake();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    // A plain org admin, not an operator: passes the `operator` middleware (admin of at
    // least one org is enough to reach the console) but is not reachable outside orgA, so
    // this exercises assertCanTouchPackage() itself rather than the middleware in front of
    // it. A Member here would be rejected by the middleware before the controller ever
    // runs, which would prove nothing about assertCanTouchPackage().
    $adminA = User::factory()->for($orgA)->create(['role' => UserRole::Admin]);
    $groupB = Group::factory()->for($orgB)->create();
    $package = Package::factory()->create(['type' => PackageType::Composer, 'source_mode' => PackageSourceMode::Git]);
    $package->groups()->attach($groupB);

    $this->actingAs($adminA)
        ->post(route('admin.packages.resync', $package))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('forbids a member from resyncing a package', function () {
    Queue::fake();
    $package = Package::factory()->create(['type' => PackageType::Composer, 'source_mode' => PackageSourceMode::Git]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->post(route('admin.packages.resync', $package))
        ->assertForbidden();

    Queue::assertNothingPushed();
});
