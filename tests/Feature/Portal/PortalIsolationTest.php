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
    $pkg = Package::factory()->inOrgOf($groupA)->create(['name' => 'acme/widget']);
    $groupA->packages()->attach($pkg);

    // Overview: only the member's own registry
    $this->actingAs($memberA)->get('/portal')
        ->assertInertia(fn ($p) => $p->has('registries', 1)->where('registries.0.slug', 'acme'));

    // Own detail: snippets + package visible
    $this->actingAs($memberA)->get("/portal/registries/{$groupA->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('snippets.npm', fn ($v) => str_contains($v, '/r/acme/'))->has('packages', 1));

    // Foreign detail: forbidden
    $this->actingAs($memberA)->get("/portal/registries/{$groupB->id}")->assertForbidden();

    // Minting sits behind `password.confirm`; the isolation this test is about is the
    // org scoping behind that gate.
    $this->withSession(['auth.password_confirmed_at' => time()]);

    // Token for own registry: ok
    $this->actingAs($memberA)->from('/portal')
        ->post('/portal/tokens', ['name' => 'CI', 'group_id' => $groupA->id])->assertRedirect('/portal');

    // Token for foreign registry: rejected
    $this->actingAs($memberA)->from('/portal')
        ->post('/portal/tokens', ['name' => 'evil', 'group_id' => $groupB->id])->assertSessionHasErrors('group_id');
});
