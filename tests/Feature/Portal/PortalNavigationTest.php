<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

it('sends a member from the dashboard to the portal', function () {
    $member = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member, 'email_verified_at' => now()]);

    $this->actingAs($member)->get('/dashboard')->assertRedirect('/portal');
});

it('keeps an admin on the dashboard', function () {
    $admin = User::factory()->for(Organization::factory())->create(['role' => UserRole::Admin, 'email_verified_at' => now()]);

    $this->actingAs($admin)->get('/dashboard')->assertOk();
});
