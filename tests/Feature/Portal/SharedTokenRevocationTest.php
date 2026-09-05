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
 * No route reaches it any more. Such a caller is refused at the owning organization's address
 * by ResolvePortalContext (404, uniform with a slug that does not exist) and at their own
 * organization's address by the controller's binding (403, PortalTokenIsolationTest). That is a
 * fact about today's callers and not evidence the clause is wrong: "never an organization the
 * user is not a member of" is a true statement about this policy at its own level, and it is
 * the last line if a fourth caller appears.
 *
 * A PERSONAL token whose owner has left, not the org-shared one this file otherwise uses. The
 * shared case cannot measure this clause at all: the branch below it asks administers(), which
 * a non-member fails anyway, so deleting the clause leaves that assertion green. The owner
 * branch is `$token->user_id === $user->id`, which a departed owner still satisfies — so this
 * is the one shape where the clause is the line that answers.
 *
 * The state is real and the application produces it: detaching a member leaves their personal
 * tokens in place and only stops them RESOLVING (TokenDeprovisioningTest). The row is still
 * there, and this says who may delete it.
 */
it('refuses the owner of a personal token once they are out of the organization', function () {
    $actor = User::factory()->for($this->home)->create(['role' => UserRole::Member]);
    $actor->organizations()->attach($this->other, ['role' => UserRole::Member->value]);
    [$personal] = RegistryToken::issue($this->other, 'personal', null, owner: $actor);

    // While the membership stands, the owner branch answers yes — without this the assertion
    // below is equally satisfied by a policy that refuses every personal token there is.
    expect((new RegistryTokenPolicy)->delete($actor, $personal))->toBeTrue();

    $actor->organizations()->detach($this->other->id);

    expect((new RegistryTokenPolicy)->delete($actor->fresh(), $personal))->toBeFalse();
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
