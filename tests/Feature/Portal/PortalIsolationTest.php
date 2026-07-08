<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

it('fully isolates two customers across list, detail, snippets and tokens', function () {
    config(['app.url' => 'https://reg.example.test']);

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $memberA = User::factory()->for($orgA)->create(['role' => UserRole::Member]);

    $groupA = Group::factory()->for($orgA)->create(['slug' => 'acme']);
    $groupB = Group::factory()->for($orgB)->create(['slug' => 'other']);
    $pkg = Package::factory()->create(['name' => 'acme/widget']);
    $groupA->packages()->attach($pkg);

    // Übersicht: nur eigene Registry
    $this->actingAs($memberA)->get('/portal')
        ->assertInertia(fn ($p) => $p->has('registries', 1)->where('registries.0.slug', 'acme'));

    // Detail eigen: Snippets + Paket sichtbar
    $this->actingAs($memberA)->get("/portal/registries/{$groupA->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('snippets.npm', fn ($v) => str_contains($v, '/r/acme/'))->has('packages', 1));

    // Detail fremd: verboten
    $this->actingAs($memberA)->get("/portal/registries/{$groupB->id}")->assertForbidden();

    // Token für eigene Registry: ok
    $this->actingAs($memberA)->from('/portal')
        ->post('/portal/tokens', ['name' => 'CI', 'group_id' => $groupA->id])->assertRedirect('/portal');

    // Token für fremde Registry: abgelehnt
    $this->actingAs($memberA)->from('/portal')
        ->post('/portal/tokens', ['name' => 'evil', 'group_id' => $groupB->id])->assertSessionHasErrors('group_id');
});
