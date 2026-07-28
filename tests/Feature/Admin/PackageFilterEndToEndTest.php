<?php

use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\Package;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    Package::factory()->create(['name' => 'acme/alpha', 'type' => 'composer', 'sync_status' => SyncStatus::Synced]);
    Package::factory()->create(['name' => 'acme/beta', 'type' => 'npm', 'sync_status' => SyncStatus::Failed]);
    Package::factory()->create(['name' => 'zeta/gamma', 'type' => 'composer', 'sync_status' => SyncStatus::Synced]);
});

it('applies combined filters and returns the expected subset', function () {
    // q=acme + type=composer → only acme/alpha
    $this->actingAs($this->admin)->get('/admin/packages?q=acme&type=composer')
        ->assertInertia(fn ($p) => $p->has('packages.data', 1)->where('packages.data.0.name', 'acme/alpha'));

    // status=synced → acme/alpha + zeta/gamma
    $this->actingAs($this->admin)->get('/admin/packages?status=synced')
        ->assertInertia(fn ($p) => $p->has('packages.data', 2));

    // no filters → all three
    $this->actingAs($this->admin)->get('/admin/packages')
        ->assertInertia(fn ($p) => $p->has('packages.data', 3));
});

it('search endpoint returns matches for a partial name', function () {
    $res = $this->actingAs($this->admin)->getJson('/admin/package-search?q=zeta');
    $res->assertOk();
    expect(collect($res->json())->pluck('name'))->toContain('zeta/gamma')->not->toContain('acme/alpha');
});
