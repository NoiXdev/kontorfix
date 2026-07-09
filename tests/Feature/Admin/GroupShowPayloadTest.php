<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes the group organization id for inline token creation', function () {
    $operatorOrg = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $operatorOrg->id, 'role' => 'admin']);
    $group = Group::factory()->create(['organization_id' => $operatorOrg->id]);

    $this->actingAs($admin)->get(route('admin.groups.show', $group->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('group.organization_id', $operatorOrg->id));
});
