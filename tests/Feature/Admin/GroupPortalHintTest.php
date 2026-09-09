<?php

use App\Models\Group;
use App\Models\Organization;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Registry create sheet (admin.groups.index) — Group::store()'s owner is resolved
| server-side (ScopesToAdministeredOrgs::resolveCreationOrg()), so the hint about the
| owning organization's portal state has to be wired the same way: per-option for an
| explicit pick in the SearchableSelect, and a "default" figure for the "Standard
| (Betreiber)" option, which resolves to the active scope org or the caller's home org.
|--------------------------------------------------------------------------
*/

it('marks an organization with the portal switched off in the create sheet organization list', function () {
    $org = Organization::factory()->create(['portal_enabled' => false]);
    $admin = adminOf($org);

    $this->actingAs($admin)->get(route('admin.groups.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/groups/Index')
            ->where('organizations.0.id', $org->id)
            ->where('organizations.0.portal_enabled', false)
            // The admin's home organization is the only one they administer, so it is
            // also what "Standard (Betreiber)" resolves to.
            ->where('default_organization_portal_enabled', false));
});

it('marks an organization with the portal switched on in the create sheet organization list', function () {
    $org = Organization::factory()->create(['portal_enabled' => true]);
    $admin = adminOf($org);

    $this->actingAs($admin)->get(route('admin.groups.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/groups/Index')
            ->where('organizations.0.portal_enabled', true)
            ->where('default_organization_portal_enabled', true));
});

it('resolves the default organization portal state from the active scope, not the home organization', function () {
    // superAdmin()'s home organization is itself operator/portal-enabled — the scoped org
    // below is a distinct, non-operator organization, so a result of `false` can only come
    // from honouring the active scope, not from falling back to the home organization.
    $scoped = Organization::factory()->create(['portal_enabled' => false]);
    $super = superAdmin();

    $this->actingAs($super)
        ->withSession(['admin.scope_org_id' => $scoped->id])
        ->get(route('admin.groups.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('default_organization_portal_enabled', false));
});

it('only lets a super-admin manage the organization from the create sheet', function () {
    $org = Organization::factory()->create();

    $this->actingAs(adminOf($org))->get(route('admin.groups.index'))
        ->assertInertia(fn (Assert $page) => $page->where('can_manage_organization', false));

    $this->actingAs(superAdmin())->get(route('admin.groups.index'))
        ->assertInertia(fn (Assert $page) => $page->where('can_manage_organization', true));
});

/*
|--------------------------------------------------------------------------
| Registry edit tab (admin.groups.show) — the organization is fixed (the group's own),
| so a single boolean is enough; no per-option list is needed here.
|--------------------------------------------------------------------------
*/

it('flags the owning organization portal state on the registry edit tab', function () {
    $org = Organization::factory()->create(['portal_enabled' => false]);
    $group = Group::factory()->create(['organization_id' => $org->id]);

    $this->actingAs(adminOf($org))->get(route('admin.groups.show', $group->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/groups/Show')
            ->where('group.organization_portal_enabled', false));
});

it('flags the owning organization portal as on when it is enabled', function () {
    $org = Organization::factory()->create(['portal_enabled' => true]);
    $group = Group::factory()->create(['organization_id' => $org->id]);

    $this->actingAs(adminOf($org))->get(route('admin.groups.show', $group->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('group.organization_portal_enabled', true));
});

it('only lets a super-admin manage the organization from the registry edit tab', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->create(['organization_id' => $org->id]);

    $this->actingAs(adminOf($org))->get(route('admin.groups.show', $group->id))
        ->assertInertia(fn (Assert $page) => $page->where('can_manage_organization', false));

    $this->actingAs(superAdmin())->get(route('admin.groups.show', $group->id))
        ->assertInertia(fn (Assert $page) => $page->where('can_manage_organization', true));
});
