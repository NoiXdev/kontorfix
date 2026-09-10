<?php

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

function directoryScopeOperatorAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('lists users of every organization when no scope is selected', function () {
    $admin = directoryScopeOperatorAdmin();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    User::factory()->for($orgA)->create();
    User::factory()->for($orgB)->create();

    $this->actingAs($admin)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('users', 3)); // admin's own account + one in each of orgA/orgB
});

it('narrows the user directory to the active scope organization', function () {
    $admin = directoryScopeOperatorAdmin();
    $orgA = Organization::factory()->create(['name' => 'Org A']);
    $orgB = Organization::factory()->create(['name' => 'Org B']);
    $userA = User::factory()->for($orgA)->create(['name' => 'User A']);
    User::factory()->for($orgB)->create(['name' => 'User B']);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $orgA->id])
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('users', 1)
            ->where('users.0.id', $userA->id)
            ->has('organizations', 1)
            ->where('organizations.0.id', $orgA->id));
});

it('narrows the robot directory to the active scope organization', function () {
    $admin = directoryScopeOperatorAdmin();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $robotA = User::factory()->for($orgA)->create(['account_type' => AccountType::Robot, 'email' => null, 'password' => null]);
    User::factory()->for($orgB)->create(['account_type' => AccountType::Robot, 'email' => null, 'password' => null]);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $orgA->id])
        ->get('/admin/robots')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('robots', 1)
            ->where('robots.0.id', $robotA->id)
            ->has('organizations', 1));
});
