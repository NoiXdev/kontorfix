<?php

use App\Models\Organization;
use App\Models\User;

it('redirects to the viewer\'s own organization when no scope is selected', function () {
    $home = Organization::factory()->create(['slug' => 'acme']);
    $other = Organization::factory()->create(['slug' => 'other-inc']);
    $user = User::factory()->create(['organization_id' => $home->id]);

    $this->actingAs($user)
        ->withSession(['admin.scope_org_id' => $other->id]) // ignored: this user does not administer $other
        ->get('/portal')
        ->assertRedirect('/c/acme');
});

it('redirects a super-admin to the selected scope organization\'s portal, not their own', function () {
    $home = Organization::factory()->create(['slug' => 'acme']);
    $selected = Organization::factory()->create(['slug' => 'selected-inc']);
    $super = User::factory()->create(['organization_id' => $home->id, 'is_super_admin' => true]);

    $this->actingAs($super)
        ->withSession(['admin.scope_org_id' => $selected->id])
        ->get('/portal')
        ->assertRedirect('/c/selected-inc');
});

it('redirects a super-admin to their own organization when no scope is selected', function () {
    $home = Organization::factory()->create(['slug' => 'acme']);
    $super = User::factory()->create(['organization_id' => $home->id, 'is_super_admin' => true]);

    $this->actingAs($super)
        ->get('/portal')
        ->assertRedirect('/c/acme');
});
