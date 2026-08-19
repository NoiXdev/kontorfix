<?php

use App\Enums\UserRole;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

it('shows the admin registry detail and the customer portal package list', function () {
    config(['app.url' => 'https://reg.example.test']);

    $operatorAdmin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    $org = Organization::factory()->create();
    $member = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $group = Group::factory()->for($org)->create(['name' => 'Kadenz', 'slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    $a = Package::factory()->create(['name' => 'acme/alpha', 'type' => 'composer']);
    $b = Package::factory()->create(['name' => 'beta/widget', 'type' => 'npm']);
    $group->packages()->attach([$a->id, $b->id]);

    // Admin-Registry-Detail: Pakete + Domain + Setup sichtbar
    $this->actingAs($operatorAdmin)->get("/admin/groups/{$group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/groups/Show')
            ->where('group.name', 'Kadenz')->has('packages', 2)->has('domains', 1)->has('setup.composer'));

    // Kunde sieht seine Portal-Paketliste — Suche/Filter laufen client-seitig
    // (useTableState, prefix 'pkg'), der Server liefert immer die volle Liste, auch
    // wenn eine alte Bookmark-URL noch bare q/type-Parameter mitschickt.
    $this->actingAs($member)->get("/portal/registries/{$group->id}?q=acme")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registry')->has('packages', 2));

    $this->actingAs($member)->get("/portal/registries/{$group->id}?type=npm")
        ->assertInertia(fn ($p) => $p->has('packages', 2));

    // Fremde Registry-Detail im Portal bleibt dicht
    $foreign = Group::factory()->for(Organization::factory()->create())->create();
    $this->actingAs($member)->get("/portal/registries/{$foreign->id}")->assertForbidden();
});
