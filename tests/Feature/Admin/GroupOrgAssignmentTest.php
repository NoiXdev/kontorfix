<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

it('creates a registry under the chosen customer organization', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($operator)->create(['role' => UserRole::Admin]);
    $cust = Organization::factory()->create();

    $this->actingAs($admin)->post('/admin/groups', [
        'name' => 'Kadenz Registry', 'slug' => 'kadenz', 'organization_id' => $cust->id,
    ])->assertRedirect();

    expect(Group::where('slug', 'kadenz')->first()->organization_id)->toBe($cust->id);
});

it('defaults to the operator org when none is given', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($operator)->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->post('/admin/groups', ['name' => 'Intern', 'slug' => 'intern'])->assertRedirect();
    expect(Group::where('slug', 'intern')->first()->organization_id)->toBe($operator->id);
});
