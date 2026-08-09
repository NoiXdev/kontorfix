<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

it('onboards a customer end-to-end: org -> user -> registry -> portal', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($operator)->create(['role' => UserRole::Admin]);

    // 1) Create the customer org
    $this->actingAs($admin)->post('/admin/organizations', ['name' => 'Kadenz GmbH', 'slug' => 'kadenz-org'])->assertRedirect();
    $cust = Organization::where('slug', 'kadenz-org')->firstOrFail();

    // 2) Create a member user in the customer org
    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'Kadenz Kunde', 'email' => 'kunde@kadenz.test', 'organization_id' => $cust->id, 'role' => 'member', 'password' => 'geheim-1234',
    ])->assertRedirect();
    $member = User::where('email', 'kunde@kadenz.test')->firstOrFail();

    // 3) Assign a registry to the customer org
    $this->actingAs($admin)->post('/admin/groups', ['name' => 'Kadenz Registry', 'slug' => 'kadenz-reg', 'organization_id' => $cust->id])->assertRedirect();

    // 4) Vendor detail page shows registry + users
    $this->actingAs($admin)->get("/admin/organizations/{$cust->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/organizations/Show')->has('registries', 1)->has('users', 1));

    // 5) The customer sees exactly their registry in the portal.
    // Fresh session: web sessions are pinned to their user's password hash
    // (AuthenticateSession), so swapping identity means a new browser session — the same
    // thing a real logout does via session()->invalidate().
    $this->flushSession();

    $this->actingAs($member)->get('/portal')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registries')->has('registries', 1)->where('registries.0.slug', 'kadenz-reg'));
});
