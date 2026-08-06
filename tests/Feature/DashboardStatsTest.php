<?php

use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\Group;
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
            ->has('recent', 1) // only the synced package has synced_at
            ->where('recent.0.status', 'synced'));
});

it('redirects members away from the dashboard to the portal', function () {
    $member = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);

    $this->actingAs($member)->get('/dashboard')->assertRedirect(route('portal.registries.index'));
});

it('surfaces failed packages in the dashboard widget with their error', function () {
    $admin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
    Package::factory()->create(['name' => 'acme/broken', 'sync_status' => SyncStatus::Failed, 'sync_error' => 'clone failed', 'synced_at' => now()]);
    Package::factory()->create(['name' => 'acme/ok', 'sync_status' => SyncStatus::Synced, 'synced_at' => now()]);

    $this->actingAs($admin)->get('/dashboard')
        ->assertInertia(fn ($p) => $p
            ->has('failedPackages', 1)
            ->where('failedPackages.0.name', 'acme/broken')
            ->where('failedPackages.0.error', 'clone failed'));
});

it('scopes the failed-packages widget to the admins own organizations', function () {
    $orgA = Organization::factory()->create();
    $adminA = User::factory()->for($orgA)->create(['role' => UserRole::Admin]);
    $groupA = Group::factory()->for($orgA)->create();

    $mine = Package::factory()->create(['name' => 'a/broken', 'sync_status' => SyncStatus::Failed, 'sync_error' => 'x', 'synced_at' => now()]);
    $groupA->packages()->attach($mine->id);

    // A failed package in a foreign org must not leak into the widget.
    $foreignGroup = Group::factory()->create();
    $foreign = Package::factory()->create(['name' => 'b/broken', 'sync_status' => SyncStatus::Failed, 'synced_at' => now()]);
    $foreignGroup->packages()->attach($foreign->id);

    $this->actingAs($adminA)->get('/dashboard')
        ->assertInertia(fn ($p) => $p->has('failedPackages', 1)->where('failedPackages.0.name', 'a/broken'));
});
