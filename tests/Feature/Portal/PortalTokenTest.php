<?php

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;

beforeEach(function () {
    $this->orgA = Organization::factory()->create();
    $this->member = User::factory()->for($this->orgA)->create(['role' => UserRole::Member]);
    // Minting through the portal sits behind `password.confirm` (see routes/web.php);
    // this file is about the org scoping behind that gate, not the gate itself, which
    // CredentialPasswordConfirmationTest covers.
    $this->withSession(['auth.password_confirmed_at' => time()]);
});

it('creates a token scoped to the members own org and returns the plaintext once', function () {
    $group = Group::factory()->for($this->orgA)->create();

    $this->actingAs($this->member)->from("/c/{$this->orgA->slug}/registries")
        ->post("/c/{$this->orgA->slug}/tokens", ['name' => 'CI', 'group_id' => $group->id, 'ability' => 'read'])
        ->assertRedirect("/c/{$this->orgA->slug}/registries")
        ->assertSessionHas('plainTextToken');

    $token = RegistryToken::first();
    expect($token->organization_id)->toBe($this->orgA->id)
        ->and($token->group_id)->toBe($group->id)
        ->and($token->ability)->toBe(TokenAbility::Read);
});

it('rejects assigning a token to a foreign registry', function () {
    $foreign = Group::factory()->for(Organization::factory()->create())->create();

    $this->actingAs($this->member)->from("/c/{$this->orgA->slug}/registries")
        ->post("/c/{$this->orgA->slug}/tokens", ['name' => 'CI', 'group_id' => $foreign->id])
        ->assertSessionHasErrors('group_id');

    expect(RegistryToken::count())->toBe(0);
});

it('revokes only own personal tokens', function () {
    // Persönliches Token des Members: löschbar.
    $own = RegistryToken::factory()->for($this->orgA)->create(['user_id' => $this->member->id]);
    $foreign = RegistryToken::factory()->for(Organization::factory()->create())->create();

    $this->actingAs($this->member)->delete("/c/{$this->orgA->slug}/tokens/{$foreign->id}")->assertForbidden();
    expect(RegistryToken::find($foreign->id))->not->toBeNull();

    $this->actingAs($this->member)->from("/c/{$this->orgA->slug}/registries")
        ->delete("/c/{$this->orgA->slug}/tokens/{$own->id}")->assertRedirect("/c/{$this->orgA->slug}/registries");
    expect(RegistryToken::find($own->id))->toBeNull();
});

it('ties a token to the organization whose portal it was minted in', function () {
    $home = Organization::factory()->create(['slug' => 'home']);
    $other = Organization::factory()->create(['slug' => 'other']);
    $user = User::factory()->create(['organization_id' => $home->id, 'role' => 'admin']);
    $user->organizations()->attach($other->id, ['role' => 'admin']);

    // Asserted on the response first: without this a 4xx would surface as a TypeError on a
    // null row rather than as a named failure, and the reader would be told nothing.
    $this->actingAs($user)->post('/c/other/tokens', ['name' => 'CI', 'ability' => 'read'])
        ->assertRedirect()->assertSessionHasNoErrors();

    // The old code fell back to the user's HOME organization when no group was submitted.
    // sole(), not latest()->first(): it asserts that exactly one row was written, and it does
    // not lean on `created_at`, whose second granularity cannot order two tokens minted in
    // the same request cycle.
    expect(RegistryToken::sole()->organization_id)->toBe($other->id);
});

it('refuses a group from another organization', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $other = Organization::factory()->create();
    $group = Group::factory()->for($other)->create();
    $user = User::factory()->create(['organization_id' => $org->id, 'role' => 'admin']);
    $user->organizations()->attach($other->id, ['role' => 'admin']);

    $this->actingAs($user)
        ->post('/c/acme/tokens', ['name' => 'CI', 'ability' => 'read', 'group_id' => $group->id])
        ->assertStatus(403);

    // The status alone would not distinguish a refusal from a refusal that still wrote.
    expect(RegistryToken::count())->toBe(0);
});

/*
 * The two operator shapes, in two tests rather than one.
 *
 * They are refused by different code, and a second assertion placed after the first in a
 * single test can never redden on its own — Pest stops at the first failure, so the shape
 * that is refused twice over would be permanently masked by the shape that is refused once.
 * The mutation that drops both guards has to be able to name both.
 */
it('refuses an operator account minting a token in a customer portal', function () {
    Organization::factory()->create(['slug' => 'acme']);

    // superAdmin() brings its own is_operator organization as its home — that home, with
    // `role === Admin`, is what makes it a super-admin (isSuperAdmin()'s grandfather clause).
    // Viewing and minting are different questions. If looking in to help also issued
    // credentials, support access would be a way to obtain a customer's registry token.
    //
    // This shape is refused by store() and by NOTHING else: Gate::before answers true before
    // RegistryTokenPolicy::create is consulted, so the guard in the controller is the single
    // thread it hangs from.
    $this->actingAs(superAdmin())
        ->post('/c/acme/tokens', ['name' => 'CI', 'ability' => 'read'])
        ->assertStatus(403);

    // No token AT ALL, which is the stronger of the two questions and implies the weaker one
    // ("none for the customer"). The old code answered 302 and minted one against the
    // operator's OWN organization, so a per-organization count would have passed.
    expect(RegistryToken::count())->toBe(0);
});

it('refuses a pivot-admin of the operator organization minting in a customer portal', function () {
    Organization::factory()->create(['slug' => 'acme']);
    $operatorOrg = Organization::factory()->create(['is_operator' => true]);

    // The second operator shape: admin of the operator organization through the pivot, home
    // in an ordinary one. It never trips isSuperAdmin()'s grandfather clause (that needs
    // `role === Admin` in an operator HOME organization), so Gate::before does not fire and
    // RegistryTokenPolicy::create refuses it as well — belt and braces where the super-admin
    // above has one thread. It does reach the portal: administersOperatorOrganization() reads
    // the pivot roles, so ResolvePortalContext admits it and the refusal has to happen in
    // store(). Nothing pinned this shape, so a change to either guard alone would have been
    // invisible from here.
    $pivotAdmin = User::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'role' => 'member',
    ]);
    $pivotAdmin->organizations()->attach($operatorOrg->id, ['role' => 'admin']);

    $this->actingAs($pivotAdmin)
        ->post('/c/acme/tokens', ['name' => 'CI', 'ability' => 'read'])
        ->assertStatus(403);

    expect(RegistryToken::count())->toBe(0);
});

it('still lets a member of the organization mint a token', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create(['organization_id' => $org->id, 'role' => 'admin']);

    // assertRedirect() alone cannot fail while the response is not 4xx: a validation error is
    // a redirect, and so is a run that writes no token at all. The two refusals above assert
    // that nothing was written; this is their mirror and has to assert that something was —
    // and which organization it was written for, since the refusals cannot tell a correct
    // acceptance from an acceptance for the wrong organization.
    $this->actingAs($user)
        ->post('/c/acme/tokens', ['name' => 'CI', 'ability' => 'read'])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('plainTextToken');

    expect(RegistryToken::count())->toBe(1)
        ->and(RegistryToken::sole()->organization_id)->toBe($org->id);
});

it('mints an org-wide token when no group_id is submitted, for the Einrichtung tabs form', function () {
    // Task 7's setup tab POSTs to this exact route with no `group_id` field at all — this is
    // that shape, pinned by name rather than only exercised in passing by the test above,
    // which asserts the organization but never the group. `group_id === null` is what makes
    // RegistryToken::issue() write an org-wide credential (see its own doc comment); a token
    // scoped to some incidental group would still pass every assertion that test makes.
    $org = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create(['organization_id' => $org->id, 'role' => 'member']);

    $this->actingAs($user)
        ->post('/c/acme/tokens', ['name' => 'org-wide'])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('plainTextToken');

    $token = RegistryToken::sole();
    expect($token->organization_id)->toBe($org->id)
        ->and($token->group_id)->toBeNull();
});
