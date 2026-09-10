<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\MirrorSource;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

// Same class of gap as GroupOrgScopeGuardTest: PackageController::probeMirror() and the
// group-attachment check inside store() both used to ask `assertAdministersOrg()`/
// `assertAdministersGroup()`, which is true for every organization at once a caller is any
// kind of super-admin — letting a scoped-down super reach a mirror source or registry
// outside their active scope.
function packageMirrorScopeSuper(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('refuses to probe a foreign-org mirror source while scoped to a different organization', function () {
    $admin = packageMirrorScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreignSource = MirrorSource::factory()->create();

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->postJson('/admin/packages/probe-mirror', [
            'mirror_source_id' => $foreignSource->id,
            'mirror_name' => 'acme/lib',
        ])
        ->assertForbidden();
});

it('refuses to attach a new package to a foreign-org registry while scoped to a different organization', function () {
    Queue::fake();
    $admin = packageMirrorScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreignGroup = Group::factory()->create();

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->post('/admin/packages', [
            'type' => 'npm', 'name' => '@acme/foreign-attach', 'source_mode' => 'publish',
            'group_ids' => [$foreignGroup->id],
        ])
        ->assertForbidden();

    expect(Package::where('name', '@acme/foreign-attach')->exists())->toBeFalse();
});
