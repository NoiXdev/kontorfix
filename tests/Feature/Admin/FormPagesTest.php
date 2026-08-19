<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

it('renders the user create page for a super admin', function () {
    // operator() creates its own operator-org as a side effect, so the total is that org
    // plus Acme — not just Acme.
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    Organization::factory()->create(['name' => 'Acme']);

    $this->actingAs($admin)
        ->get(route('admin.users.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/Create')
            // The select's options must reach the page. Without this the field renders
            // empty and nothing errors — the failure mode this whole task risks.
            ->has('organizations', 2));
});

it('refuses the user create page for a non-super user', function () {
    // An organization admin passes the `operator` gate (they administer their own org) but
    // not `super`. A plain member fails both gates, so it would not catch a route
    // accidentally dropped from the `super` group into `operator` — this fixture is what
    // makes that mutation observable.
    $orgAdmin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($orgAdmin)
        ->get(route('admin.users.create'))
        ->assertForbidden();
});

it('renders the user edit page with the record, not a listing row', function () {
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    $org = Organization::factory()->create(['name' => 'Acme']);
    $target = User::factory()->create(['name' => 'Zoe', 'organization_id' => $org->id]);

    $this->actingAs($admin)
        ->get(route('admin.users.edit', $target))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/Edit')
            ->where('user.name', 'Zoe')
            ->where('user.organization_id', $org->id)
            // Same operator()-side-effect accounting as the create-page test above.
            ->has('organizations', 2));
});

it('refuses the user edit page for a non-super user', function () {
    // Same reasoning as the create-page refusal test above: an org admin, not a plain member.
    $orgAdmin = User::factory()->create(['role' => UserRole::Admin]);
    $target = User::factory()->create();

    $this->actingAs($orgAdmin)
        ->get(route('admin.users.edit', $target))
        ->assertForbidden();
});
