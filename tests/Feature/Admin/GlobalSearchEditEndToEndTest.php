<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

it('finds a registry via global search, renames it, and finds it under the new name', function () {
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    $group = Group::factory()->for(Organization::factory())->create(['name' => 'Kadenz Registry', 'slug' => 'kadenz']);

    // 1) Globale Suche findet die Registry
    $this->actingAs($admin)->getJson('/admin/search?q=Kadenz')
        ->assertOk()
        ->assertJsonPath('registries.0.name', 'Kadenz Registry');

    // 2) Umbenennen (Slug bleibt)
    $this->actingAs($admin)->put("/admin/groups/{$group->id}", ['name' => 'Umbenannt', 'public' => true, 'slug' => 'hack'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $fresh = $group->fresh();
    expect($fresh->name)->toBe('Umbenannt')->and($fresh->slug)->toBe('kadenz')->and($fresh->public)->toBeTrue();

    // 3) Suche findet sie unter dem neuen Namen, nicht mehr unter dem alten
    $this->actingAs($admin)->getJson('/admin/search?q=Umbenannt')
        ->assertOk()->assertJsonPath('registries.0.name', 'Umbenannt');
    $this->actingAs($admin)->getJson('/admin/search?q=Kadenz')
        ->assertOk()->assertJsonPath('registries', []);
});
