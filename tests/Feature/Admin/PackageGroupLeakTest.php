<?php

// `assertCanTouchPackage()` asserts that ONE of a package's registries is reachable in the
// caller's scope. The show page then serialised the whole relation, so a customer-org admin
// read the id, name and slug of every other organization's registry the package is shared
// into. Only a super-admin can create that shared state, which is why it is small — the
// caller still has no business reading it.

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

it('shows the caller only the registries in their own scope, and a count for the rest', function () {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();

    $myGroup = Group::factory()->for($mine)->create(['name' => 'Meine Registry']);
    $foreignGroup = Group::factory()->for($theirs)->create(['name' => 'Fremde Registry', 'slug' => 'geheim-fremd']);

    $package = Package::factory()->create();
    $package->groups()->attach([$myGroup->id, $foreignGroup->id]);

    $admin = User::factory()->for($mine)->create(['role' => UserRole::Admin]);

    $response = $this->actingAs($admin)->get("/admin/packages/{$package->id}")->assertOk();

    $props = $response->viewData('page')['props'];
    $slugs = array_column($props['groups'], 'slug');

    expect($slugs)->toBe([$myGroup->slug])
        ->and($props['sharedElsewhere'])->toBe(1);

    $response->assertDontSee('geheim-fremd')->assertDontSee('Fremde Registry');
});

it('still shows every registry to a super-admin spanning all organizations', function () {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $package = Package::factory()->create();
    $package->groups()->attach([
        Group::factory()->for($a)->create()->id,
        Group::factory()->for($b)->create()->id,
    ]);

    $super = User::factory()->for($a)->create(['role' => UserRole::Admin, 'is_super_admin' => true]);

    $props = $this->actingAs($super)->get("/admin/packages/{$package->id}")
        ->assertOk()->viewData('page')['props'];

    expect($props['groups'])->toHaveCount(2)
        ->and($props['sharedElsewhere'])->toBe(0);
});
