<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

function groupAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('assigns existing packages to a registry from the group view', function () {
    $admin = groupAdmin();
    $group = Group::factory()->for(Organization::factory())->create();
    $a = Package::factory()->create();
    $b = Package::factory()->create();

    $this->actingAs($admin)->post(route('admin.groups.packages.store', $group->id), [
        'package_ids' => [$a->id, $b->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($group->packages()->pluck('packages.id')->all())->toContain($a->id)->toContain($b->id);
});

it('keeps already-assigned packages when adding more', function () {
    $admin = groupAdmin();
    $group = Group::factory()->for(Organization::factory())->create();
    $a = Package::factory()->create();
    $b = Package::factory()->create();
    $group->packages()->attach($a->id);

    $this->actingAs($admin)->post(route('admin.groups.packages.store', $group->id), ['package_ids' => [$b->id]])
        ->assertRedirect();

    expect($group->packages()->count())->toBe(2);
});

it('removes a package from a registry', function () {
    $admin = groupAdmin();
    $group = Group::factory()->for(Organization::factory())->create();
    $pkg = Package::factory()->create();
    $group->packages()->attach($pkg->id);

    $this->actingAs($admin)->delete(route('admin.groups.packages.destroy', [$group->id, $pkg->id]))
        ->assertRedirect();

    expect($group->packages()->count())->toBe(0);
});

it('toggles the portal visibility of a group', function () {
    $admin = groupAdmin();
    $group = Group::factory()->for(Organization::factory())->create(['portal_enabled' => true]);

    $this->actingAs($admin)->put(route('admin.groups.update', $group->id), [
        'name' => $group->name,
        'public' => false,
        'portal_enabled' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($group->fresh()->portal_enabled)->toBeFalse();
});

it('creates a collection-only group with the portal disabled', function () {
    $admin = groupAdmin();

    $this->actingAs($admin)->post(route('admin.groups.store'), [
        'name' => 'Shared Libs',
        'slug' => 'shared-libs',
        'public' => false,
        'portal_enabled' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Group::where('slug', 'shared-libs')->sole()->portal_enabled)->toBeFalse();
});

it('forbids non-operator members from assigning packages', function () {
    $member = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Member]);
    $group = Group::factory()->for(Organization::factory())->create();
    $pkg = Package::factory()->create();

    $this->actingAs($member)->post(route('admin.groups.packages.store', $group->id), ['package_ids' => [$pkg->id]])
        ->assertForbidden();
});
