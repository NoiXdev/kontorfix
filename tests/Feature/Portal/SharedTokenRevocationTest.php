<?php

// An org-shared registry token (user_id null) may be revoked by an admin/maintainer of the
// organization that owns it. The policy asked `$user->role` — the role column of the
// caller's HOME organization — which answers a different question: it let an admin at home
// revoke a shared credential in any organization they merely belong to, and refused a
// pivot-admin their own.
//
// Addressed through the OWNING organization's portal, which is the only address that reaches
// the policy at all now that Portal\TokenController::destroy() binds the token to the
// organization the URL names. These cases used to stand in the caller's home portal and act
// on another organization's token — the very shape the binding refuses — so the policy was
// being measured through an address that no longer means what the test needed it to mean.
// Both callers below are members of the owning organization, so ResolvePortalContext lets
// them in and the POLICY is the line that answers, which is what this file is about.

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use App\Policies\RegistryTokenPolicy;

beforeEach(function () {
    $this->home = Organization::factory()->create();
    $this->other = Organization::factory()->create();
    $this->withSession(['auth.password_confirmed_at' => time()]);
});

it('refuses a plain member of the owning org who is only an admin at home', function () {
    $actor = User::factory()->for($this->home)->create(['role' => UserRole::Admin]);
    $actor->organizations()->attach($this->other, ['role' => UserRole::Member->value]);

    $shared = RegistryToken::factory()->for($this->other)->create(['user_id' => null]);

    $this->actingAs($actor)->from("/c/{$this->other->slug}/registries")
        ->delete("/c/{$this->other->slug}/tokens/{$shared->id}")
        ->assertForbidden();

    expect(RegistryToken::find($shared->id))->not->toBeNull();
});

it('allows an admin of the owning org who is only a member at home', function () {
    $actor = User::factory()->for($this->home)->create(['role' => UserRole::Member]);
    $actor->organizations()->attach($this->other, ['role' => UserRole::Admin->value]);

    $shared = RegistryToken::factory()->for($this->other)->create(['user_id' => null]);

    $this->actingAs($actor)->from("/c/{$this->other->slug}/registries")
        ->delete("/c/{$this->other->slug}/tokens/{$shared->id}")
        ->assertRedirect();

    expect(RegistryToken::find($shared->id))->toBeNull();
});

/*
 * The policy's non-member clause, called DIRECTLY — the way GroupPolicyTest pins
 * `portal_enabled` for the same reason, and stated here rather than left to a route.
 *
 * No route reaches it any more. A caller who belongs to neither organization is refused at
 * the owning organization's address by ResolvePortalContext (404, uniform with a slug that
 * does not exist) and at their own organization's address by the controller's binding (403,
 * PortalTokenIsolationTest). That is a fact about today's callers and not evidence the
 * clause is wrong: "never an organization the user is not a member of" is a true statement
 * about this policy at its own level, and it is the last line if a fourth caller appears.
 *
 * This case used to reach it through the caller's home portal while the token belonged
 * elsewhere. It went on passing after the binding landed — with the binding answering, not
 * the policy — which is exactly the shape where the more defended layer becomes invisible.
 */
it('refuses a caller who belongs to the owning organization not at all', function () {
    $actor = User::factory()->for($this->home)->create(['role' => UserRole::Admin]);
    $shared = RegistryToken::factory()->for($this->other)->create(['user_id' => null]);

    expect((new RegistryTokenPolicy)->delete($actor, $shared))->toBeFalse();
});

it('does not confirm the owning organization to a caller who is not in it', function () {
    // The HTTP shape of the same population, so the refusal above is not the only thing
    // standing between that caller and the token. The portal gate answers first and answers
    // 404 — the uniform response that keeps a customer slug from being guessed — and the row
    // is still there afterwards, which a status assertion alone cannot say.
    $actor = User::factory()->for($this->home)->create(['role' => UserRole::Admin]);
    $shared = RegistryToken::factory()->for($this->other)->create(['user_id' => null]);

    $this->actingAs($actor)->from("/c/{$this->home->slug}/registries")
        ->delete("/c/{$this->other->slug}/tokens/{$shared->id}")
        ->assertNotFound();

    expect(RegistryToken::find($shared->id))->not->toBeNull();
});
