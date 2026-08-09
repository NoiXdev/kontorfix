<?php

// An org-shared registry token (user_id null) may be revoked by an admin/maintainer of the
// organization that owns it. The policy asked `$user->role` — the role column of the
// caller's HOME organization — which answers a different question: it let an admin at home
// revoke a shared credential in any organization they merely belong to, and refused a
// pivot-admin their own.

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;

beforeEach(function () {
    $this->home = Organization::factory()->create();
    $this->other = Organization::factory()->create();
    $this->withSession(['auth.password_confirmed_at' => time()]);
});

it('refuses a plain member of the owning org who is only an admin at home', function () {
    $actor = User::factory()->for($this->home)->create(['role' => UserRole::Admin]);
    $actor->organizations()->attach($this->other, ['role' => UserRole::Member->value]);

    $shared = RegistryToken::factory()->for($this->other)->create(['user_id' => null]);

    $this->actingAs($actor)->from('/portal')
        ->delete("/portal/tokens/{$shared->id}")
        ->assertForbidden();

    expect(RegistryToken::find($shared->id))->not->toBeNull();
});

it('allows an admin of the owning org who is only a member at home', function () {
    $actor = User::factory()->for($this->home)->create(['role' => UserRole::Member]);
    $actor->organizations()->attach($this->other, ['role' => UserRole::Admin->value]);

    $shared = RegistryToken::factory()->for($this->other)->create(['user_id' => null]);

    $this->actingAs($actor)->from('/portal')
        ->delete("/portal/tokens/{$shared->id}")
        ->assertRedirect();

    expect(RegistryToken::find($shared->id))->toBeNull();
});

it('still refuses an organization the caller does not belong to at all', function () {
    $actor = User::factory()->for($this->home)->create(['role' => UserRole::Admin]);
    $shared = RegistryToken::factory()->for($this->other)->create(['user_id' => null]);

    $this->actingAs($actor)->from('/portal')
        ->delete("/portal/tokens/{$shared->id}")
        ->assertForbidden();

    expect(RegistryToken::find($shared->id))->not->toBeNull();
});
