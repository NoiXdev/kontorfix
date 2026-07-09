<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

it('shows the status page to an operator with health checks', function () {
    $admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/admin/status')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/status/Index')
            ->has('checks')
            ->where('checks', fn ($checks) => collect($checks)->contains(fn ($c) => $c['key'] === 'database' && $c['ok'] === true)));
});

it('is not reachable for regular members', function () {
    $member = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);
    $this->actingAs($member)->get('/admin/status')->assertForbidden();
});
