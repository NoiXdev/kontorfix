<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->org = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin']);
    [, $this->plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);
});

it('creates, updates and lists registries', function () {
    $this->withToken($this->plain)->postJson('/api/v1/groups', [
        'name' => 'Acme',
        'slug' => 'acme',
        'public' => false,
        'organization_id' => $this->org->id,
    ])->assertCreated()->assertJsonPath('data.slug', 'acme');

    $group = Group::firstWhere('slug', 'acme');

    $this->withToken($this->plain)->putJson("/api/v1/groups/{$group->id}", [
        'name' => 'Acme Corp', 'public' => true,
    ])->assertOk()->assertJsonPath('data.name', 'Acme Corp');

    $this->withToken($this->plain)->getJson('/api/v1/groups')
        ->assertOk()->assertJsonPath('data.0.name', 'Acme Corp');
});

it('deletes a registry', function () {
    $group = Group::factory()->create(['organization_id' => $this->org->id]);
    $this->withToken($this->plain)->deleteJson("/api/v1/groups/{$group->id}")->assertNoContent();
    expect(Group::find($group->id))->toBeNull();
});

it('changes a registry slug over the api and keeps it unique within the organization', function () {
    $group = Group::factory()->create(['organization_id' => $this->org->id, 'slug' => 'acme']);
    Group::factory()->create(['organization_id' => $this->org->id, 'slug' => 'belegt']);

    // The slug actually takes effect. UpdateGroupRequest is shared with the console, so
    // validating it here and then dropping it would leave org-scoped uniqueness and
    // App\Rules\UnclaimedSlug enforced on the console only — and the API is the easier of
    // the two paths for a script to take.
    $this->withToken($this->plain)->putJson("/api/v1/groups/{$group->id}", [
        'name' => 'Acme', 'public' => false, 'slug' => 'umbenannt',
    ])->assertOk()->assertJsonPath('data.slug', 'umbenannt');

    expect($group->fresh()->slug)->toBe('umbenannt');

    // …and is refused when the organization already has it. The uniqueness rule reads the
    // organization off the route-bound registry: were the API's `{group}` parameter ever
    // renamed, UpdateGroupRequest would see null, Rule::unique()->where('organization_id',
    // null) would degrade into whereNull() and match nothing, and this request would reach
    // the (organization_id, slug) index as a 500 QueryException instead of a 422.
    $this->withToken($this->plain)->putJson("/api/v1/groups/{$group->id}", [
        'name' => 'Acme', 'public' => false, 'slug' => 'belegt',
    ])->assertStatus(422)->assertJsonValidationErrors('slug');

    expect($group->fresh()->slug)->toBe('umbenannt');
});
