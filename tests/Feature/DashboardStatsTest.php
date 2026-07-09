<?php

use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

it('shows real operational stats to an operator', function () {
    $admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
    Package::factory()->create(['type' => 'composer', 'sync_status' => SyncStatus::Synced, 'synced_at' => now()]);
    Package::factory()->create(['type' => 'npm', 'sync_status' => SyncStatus::Failed]);

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Dashboard')
            ->where('stats.packages', 2)
            ->where('stats.composer', 1)
            ->where('stats.npm', 1)
            ->where('stats.sync.synced', 1)
            ->where('stats.sync.failed', 1)
            ->has('recent', 1) // nur das synchronisierte Paket hat synced_at
            ->where('recent.0.status', 'synced'));
});

it('redirects members away from the dashboard to the portal', function () {
    $member = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);

    $this->actingAs($member)->get('/dashboard')->assertRedirect(route('portal.registries.index'));
});
