<?php

use App\Enums\UserRole;
use App\Models\Domain;
use App\Models\Group;
use App\Models\User;

it('lists domains with their group for admins', function () {
    Domain::factory()->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->get('/admin/domains')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/domains/Index')->has('domains', 1));
});

it('creates a domain for a group', function () {
    $group = Group::factory()->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->post('/admin/domains', ['group_id' => $group->id, 'hostname' => 'packages.kadenz.de'])
        ->assertRedirect();

    expect(Domain::where('hostname', 'packages.kadenz.de')->first()->group_id)->toBe($group->id);
});

it('rejects an invalid or duplicate hostname', function () {
    Domain::factory()->create(['hostname' => 'packages.kadenz.de']);
    $group = Group::factory()->create();
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    // Duplicate
    $this->actingAs($admin)->post('/admin/domains', ['group_id' => $group->id, 'hostname' => 'packages.kadenz.de'])
        ->assertSessionHasErrors('hostname');
    // invalid format (not an FQDN, with scheme/slash)
    $this->actingAs($admin)->post('/admin/domains', ['group_id' => $group->id, 'hostname' => 'https://x/y'])
        ->assertSessionHasErrors('hostname');
    $this->actingAs($admin)->post('/admin/domains', ['group_id' => $group->id, 'hostname' => 'not a host'])
        ->assertSessionHasErrors('hostname');
});

it('deletes a domain', function () {
    $domain = Domain::factory()->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->delete("/admin/domains/{$domain->id}")->assertRedirect();
    expect(Domain::find($domain->id))->toBeNull();
});

it('forbids members', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->get('/admin/domains')->assertForbidden();
});
