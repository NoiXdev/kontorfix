<?php

// One namespace, two tables. The registry URL is /r/{orgSlug}/{groupSlug} and the legacy
// one-segment /r/{slug} route is matched after it, so an organization whose slug equals some
// registry's slug makes /r/{that}/foo resolve to registry `foo` of that organization instead
// of to the legacy registry. The migration refuses to upgrade an instance holding such a
// pair; these are what keep one from being created afterwards, which the migration alone
// cannot do.

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
});

it('refuses an organization slug that a registry already answers to', function () {
    Group::factory()->create(['slug' => 'kadenz']);

    $this->actingAs($this->admin)
        ->post('/admin/organizations', ['name' => 'Kadenz GmbH', 'slug' => 'kadenz'])
        ->assertSessionHasErrors('slug');

    expect(Organization::where('slug', 'kadenz')->exists())->toBeFalse();
});

it('refuses a registry slug that an organization already answers to', function () {
    // In ANY organization, not just the one the registry is being created in: the legacy
    // URL is one segment, so the ambiguity is instance-wide.
    Organization::factory()->create(['slug' => 'kadenz']);

    $this->actingAs($this->admin)
        ->post('/admin/groups', ['name' => 'Kadenz', 'slug' => 'kadenz'])
        ->assertSessionHasErrors('slug');

    expect(Group::where('slug', 'kadenz')->exists())->toBeFalse();
});

it('still allows an organization slug no registry holds', function () {
    // The counterpart, so the refusals above cannot be satisfied by rejecting everything.
    Group::factory()->create(['slug' => 'kadenz']);

    $this->actingAs($this->admin)
        ->post('/admin/organizations', ['name' => 'Andere GmbH', 'slug' => 'andere'])
        ->assertSessionHasNoErrors();

    expect(Organization::where('slug', 'andere')->exists())->toBeTrue();
});

it('still allows a registry slug no organization holds', function () {
    Organization::factory()->create(['slug' => 'kadenz']);

    $this->actingAs($this->admin)
        ->post('/admin/groups', ['name' => 'Pakete', 'slug' => 'pakete'])
        ->assertSessionHasNoErrors();

    expect(Group::where('slug', 'pakete')->exists())->toBeTrue();
});
