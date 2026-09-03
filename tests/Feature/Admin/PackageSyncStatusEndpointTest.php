<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

/**
 * An Admin of the operator organization — the super-admin-equivalent. Named uniquely
 * because every test file in this suite loads into the same process and a duplicated
 * top-level function name is a fatal redeclaration (see PackageResyncTest's note).
 */
function syncStatusOperator(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('reports a pending package as pending', function () {
    $package = Package::factory()->create([
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Git,
        'sync_status' => SyncStatus::Pending,
        'sync_error' => null,
    ]);

    $this->actingAs(syncStatusOperator())
        ->getJson(route('admin.packages.sync-status', $package))
        ->assertOk()
        ->assertExactJson(['status' => 'pending', 'error' => null]);
});

// The point of the endpoint: the detail page polls it until it flips, because the
// PackageSynced broadcast may have gone out before this browser ever subscribed.
it('reports the terminal status once the job has finished', function () {
    $package = Package::factory()->create([
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Git,
        'sync_status' => SyncStatus::Pending,
    ]);

    $package->update(['sync_status' => SyncStatus::Synced, 'sync_error' => null]);

    $this->actingAs(syncStatusOperator())
        ->getJson(route('admin.packages.sync-status', $package))
        ->assertOk()
        ->assertExactJson(['status' => 'synced', 'error' => null]);
});

it('reports the failure text alongside a failed status', function () {
    $package = Package::factory()->create([
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Git,
        'sync_status' => SyncStatus::Failed,
        'sync_error' => 'Repository nicht erreichbar.',
    ]);

    $this->actingAs(syncStatusOperator())
        ->getJson(route('admin.packages.sync-status', $package))
        ->assertOk()
        ->assertExactJson(['status' => 'failed', 'error' => 'Repository nicht erreichbar.']);
});

// Same guard as every other read of this package. `sync_error` can quote raw output from
// the tenant's repository, so this must not become a softer door into it than `show`.
it('forbids reading the status of a package outside the administered org', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    // A plain org admin of orgA: past the `operator` middleware, but with no reach into
    // orgB — so this exercises assertCanTouchPackage() rather than the middleware.
    $adminA = User::factory()->for($orgA)->create(['role' => UserRole::Admin]);
    $groupB = Group::factory()->for($orgB)->create();
    $package = Package::factory()->for($orgB)->create([
        'type' => PackageType::Composer,
        'sync_status' => SyncStatus::Failed,
        'sync_error' => 'https://x-access-token:secret@git.example.com/orgb/private.git nicht erreichbar.',
    ]);
    $package->groups()->attach($groupB);

    $this->actingAs($adminA)
        ->getJson(route('admin.packages.sync-status', $package))
        ->assertForbidden();
});

it('forbids a member from reading the status', function () {
    $package = Package::factory()->create(['type' => PackageType::Composer]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->getJson(route('admin.packages.sync-status', $package))
        ->assertForbidden();
});

it('refuses an unauthenticated request', function () {
    // A user has to exist, otherwise the setup-wizard gate answers first with a redirect
    // to /setup and this proves nothing about the auth middleware behind it.
    syncStatusOperator();
    $package = Package::factory()->create(['type' => PackageType::Composer]);

    // 401 rather than a redirect to the login page: the detail page calls this with
    // `Accept: application/json`, and a poll that silently followed a redirect would parse
    // the login page as a status answer.
    $this->getJson(route('admin.packages.sync-status', $package))->assertUnauthorized();
});

// `packages/{package}` and `packages/{package}/sync-status` sit next to each other in the
// route table; a wildcard that swallowed the second segment would answer this with the
// full Inertia detail page instead of the two columns asked for.
it('does not collide with the detail route', function () {
    $package = Package::factory()->create(['type' => PackageType::Composer, 'sync_status' => SyncStatus::Syncing]);

    $response = $this->actingAs(syncStatusOperator())->getJson(route('admin.packages.sync-status', $package));

    $response->assertOk()->assertExactJson(['status' => 'syncing', 'error' => null]);
    expect($response->headers->get('content-type'))->toContain('application/json');
});
