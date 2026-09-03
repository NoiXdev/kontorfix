<?php

/*
 * WHO may assign a shared package, and who may end or re-date such an assignment (spec §4,
 * as rewritten after Task 6).
 *
 * Sharing and assigning are two gates, deliberately. `shared` says a package MAY leave the
 * operator organization; the assignment says which customer actually receives it, and the
 * operator makes that decision per customer. Collapsing the two would make `shared` mean
 * "available to everyone who finds it", which removes the per-customer control the decision
 * exists to provide — and that is precisely what the code did: the attach guard admitted
 * `own OR shared` for everyone, so any customer admin could help themselves to any shared
 * package of the instance.
 *
 * The rule, one sentence, over the package rather than over the direction of the write:
 *
 *   assigning a shared package, detaching one, and editing such an assignment's
 *   availability all require administering the organization that OWNS the package.
 *
 * A customer's own packages are untouched by it, and every case below is asserted in both
 * directions for that reason: a guard that refused everything would satisfy the refusals
 * alone. Each refusal therefore comes with (a) the same act by someone who administers the
 * owner, and (b) the same act on an OWN package by the customer admin — the two mutations
 * "drop the check" and "reverse the check" redden disjoint halves of this file.
 *
 * The privileged actor is never a super-admin. `User::isSuperAdmin()` administers every
 * organization, so it satisfies any operator check trivially and could not tell a rule
 * keyed on the package's owner from one keyed on a global flag. `operatorStaff()` below is
 * an ordinary account that happens to administer both organizations — the shape an operator
 * employee actually has, and the one that proves the rule reads
 * `administeredOrganizationIds()`.
 *
 * Every case runs through BOTH write surfaces where both exist. The two resolve the caller
 * and the target their own way and each asks the guard itself; this repository has shipped
 * the console-guarded / API-unguarded gap four times, twice on this branch.
 */

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\GroupPackage;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->customer = Organization::factory()->create(['is_operator' => false]);
    $this->registry = Group::factory()->for($this->customer)->create();

    // The receiving customer's own admin: full authority over their own registry and their
    // own packages, none at all over the operator's shared ones.
    $this->customerAdmin = User::factory()->for($this->customer)->create(['role' => UserRole::Admin]);

    $this->shared = Package::factory()->for($this->operator)
        ->create(['type' => 'composer', 'name' => 'betrieb/geteilt', 'shared' => true]);
    $this->own = Package::factory()->for($this->customer)
        ->create(['type' => 'composer', 'name' => 'kunde/eigen']);
});

/**
 * An ordinary account that administers BOTH organizations: admin of the customer (so it may
 * touch the registry at all) and maintainer of the operator organization that owns the
 * shared package (so it may decide who receives it). Not a super-admin — its home role is
 * Admin of a non-operator organization, so `isSuperAdmin()`'s grandfather clause does not
 * fire and `is_super_admin` is unset.
 */
function operatorStaff(Organization $customer, Organization $operator): User
{
    $user = User::factory()->for($customer)->create(['role' => UserRole::Admin]);
    $user->organizations()->attach($operator->id, ['role' => UserRole::Maintainer->value]);

    return $user;
}

/** A write-scoped API key for the given account — the /api/v1 counterpart of actingAs(). */
function authorityKeyFor(User $user): string
{
    [, $plain] = ApiKey::issue($user, 'shared-assignment-authority', ApiKeyPermission::Write);

    return $plain;
}

/** The pivot row itself, which is what a detach removes and an availability edit rewrites. */
function assignmentOf(Group $group, Package $package): ?GroupPackage
{
    return GroupPackage::where('group_id', $group->id)->where('package_id', $package->id)->first();
}

// ---------------------------------------------------------------------------------------
// Assigning — Admin\GroupController::attachPackages (syncWithoutDetaching).
// ---------------------------------------------------------------------------------------

it('refuses a customer admin assigning a shared package to their own registry', function () {
    $this->actingAs($this->customerAdmin)
        ->post(route('admin.groups.packages.store', $this->registry), ['package_ids' => [$this->shared->id]])
        ->assertForbidden();

    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeFalse();
});

it('lets someone who administers the owning organization assign a shared package', function () {
    $this->actingAs(operatorStaff($this->customer, $this->operator))
        ->post(route('admin.groups.packages.store', $this->registry), ['package_ids' => [$this->shared->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeTrue();
});

it('still lets a customer admin assign their own package', function () {
    $this->actingAs($this->customerAdmin)
        ->post(route('admin.groups.packages.store', $this->registry), ['package_ids' => [$this->own->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->registry->packages()->whereKey($this->own->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------------------
// Assigning — registry creation, where the same guard runs before the registry exists.
// ---------------------------------------------------------------------------------------

it('refuses a customer admin creating a registry seeded with a shared package', function () {
    $this->actingAs($this->customerAdmin)
        ->post(route('admin.groups.store'), [
            'name' => 'Selbstbedienung',
            'slug' => 'selbstbedienung',
            'package_ids' => [$this->shared->id],
        ])
        ->assertForbidden();

    // Refused before the insert, so there is no empty registry left behind either.
    expect(Group::where('slug', 'selbstbedienung')->exists())->toBeFalse();
});

it('refuses a customer admin creating a registry seeded with a shared package through the api', function () {
    $this->withToken(authorityKeyFor($this->customerAdmin))
        ->postJson('/api/v1/groups', [
            'name' => 'Selbstbedienung',
            'slug' => 'selbstbedienung-api',
            'package_ids' => [$this->shared->id],
        ])
        ->assertForbidden();

    expect(Group::where('slug', 'selbstbedienung-api')->exists())->toBeFalse();
});

it('still lets a customer admin create a registry seeded with their own package', function () {
    $this->actingAs($this->customerAdmin)
        ->post(route('admin.groups.store'), [
            'name' => 'Eigenes',
            'slug' => 'eigenes',
            'package_ids' => [$this->own->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Group::where('slug', 'eigenes')->firstOrFail()->packages()->whereKey($this->own->id)->exists())
        ->toBeTrue();
});

// ---------------------------------------------------------------------------------------
// Detaching — Admin\GroupController::detachPackage. Per spec §4 as amended, detaching is
// also the one act that releases the name back to the upstream, so a customer doing it
// unilaterally reopens a private name for their own builds.
// ---------------------------------------------------------------------------------------

it('refuses a customer admin detaching a shared package from their own registry', function () {
    $this->registry->packages()->attach($this->shared->id);

    $this->actingAs($this->customerAdmin)
        ->delete(route('admin.groups.packages.destroy', [$this->registry, $this->shared]))
        ->assertForbidden();

    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeTrue();
});

it('lets someone who administers the owning organization detach a shared package', function () {
    $this->registry->packages()->attach($this->shared->id);

    $this->actingAs(operatorStaff($this->customer, $this->operator))
        ->delete(route('admin.groups.packages.destroy', [$this->registry, $this->shared]))
        ->assertRedirect();

    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeFalse();
});

it('still lets a customer admin detach their own package', function () {
    $this->registry->packages()->attach($this->own->id);

    $this->actingAs($this->customerAdmin)
        ->delete(route('admin.groups.packages.destroy', [$this->registry, $this->own]))
        ->assertRedirect();

    expect($this->registry->packages()->whereKey($this->own->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------
// Availability — Admin\GroupController::updateAssignment. Re-dating decides how long the
// customer receives the package, in both directions: pushing a lapsed share back into force
// and ending a live one are the same write.
// ---------------------------------------------------------------------------------------

it('refuses a customer admin changing the availability of a shared assignment', function () {
    $this->registry->packages()->attach($this->shared->id, ['available_until' => null]);

    $this->actingAs($this->customerAdmin)
        ->put(route('admin.groups.packages.update', [$this->registry, $this->shared]), [
            'available_until' => '2027-12-31',
        ])
        ->assertForbidden();

    expect(assignmentOf($this->registry, $this->shared)?->available_until)->toBeNull();
});

it('refuses a customer admin ending a shared assignment by back-dating it', function () {
    // The reverse direction of the same write, and the one a rule phrased as "may not extend
    // a share" would miss: a past date stops delivery at once (see updateAssignment()).
    $this->registry->packages()->attach($this->shared->id, ['available_until' => null]);

    $this->actingAs($this->customerAdmin)
        ->put(route('admin.groups.packages.update', [$this->registry, $this->shared]), [
            'available_until' => now()->subDay()->toDateString(),
        ])
        ->assertForbidden();

    expect(assignmentOf($this->registry, $this->shared)?->available_until)->toBeNull();
});

it('lets someone who administers the owning organization change a shared assignments availability', function () {
    $this->registry->packages()->attach($this->shared->id, ['available_until' => null]);

    $this->actingAs(operatorStaff($this->customer, $this->operator))
        ->put(route('admin.groups.packages.update', [$this->registry, $this->shared]), [
            'available_until' => '2027-12-31',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(assignmentOf($this->registry, $this->shared)?->available_until?->toDateString())->toBe('2027-12-31');
});

it('still lets a customer admin change the availability of their own packages assignment', function () {
    $this->registry->packages()->attach($this->own->id, ['available_until' => null]);

    $this->actingAs($this->customerAdmin)
        ->put(route('admin.groups.packages.update', [$this->registry, $this->own]), [
            'available_until' => '2027-12-31',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(assignmentOf($this->registry, $this->own)?->available_until?->toDateString())->toBe('2027-12-31');
});

it('still answers 404 for a package this registry does not carry at all', function () {
    // The guard is asked AFTER the existence check, deliberately: otherwise the status code
    // would tell an outsider which unassigned package ids are shared.
    $this->actingAs($this->customerAdmin)
        ->put(route('admin.groups.packages.update', [$this->registry, $this->shared]), [
            'available_until' => '2027-12-31',
        ])
        ->assertNotFound();
});

// ---------------------------------------------------------------------------------------
// The API's PUT replaces the whole assignment (sync), so it expresses a DETACH BY OMISSION.
// That write passes the attach guard untouched — it only ever sees what was submitted — so
// the dropped set is asked the question separately.
// ---------------------------------------------------------------------------------------

it('refuses a customer admin assigning a shared package through the api', function () {
    $this->withToken(authorityKeyFor($this->customerAdmin))
        ->putJson("/api/v1/groups/{$this->registry->id}/packages", ['package_ids' => [$this->shared->id]])
        ->assertForbidden();

    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeFalse();
});

it('refuses a customer admin detaching a shared package by omitting it through the api', function () {
    $this->registry->packages()->attach([$this->shared->id, $this->own->id]);

    // Nothing shared is submitted, so nothing shared reaches the attach guard — and the
    // write would still drop the shared assignment.
    $this->withToken(authorityKeyFor($this->customerAdmin))
        ->putJson("/api/v1/groups/{$this->registry->id}/packages", ['package_ids' => [$this->own->id]])
        ->assertForbidden();

    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeTrue();
});

it('refuses a customer admin emptying a registry that carries a shared package through the api', function () {
    // The same omission with no submission at all — `package_ids` is optional here, and an
    // absent key must not be a way around the rule.
    $this->registry->packages()->attach($this->shared->id);

    $this->withToken(authorityKeyFor($this->customerAdmin))
        ->putJson("/api/v1/groups/{$this->registry->id}/packages", [])
        ->assertForbidden();

    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeTrue();
});

it('lets someone who administers the owning organization detach a shared package through the api', function () {
    $this->registry->packages()->attach([$this->shared->id, $this->own->id]);

    $this->withToken(authorityKeyFor(operatorStaff($this->customer, $this->operator)))
        ->putJson("/api/v1/groups/{$this->registry->id}/packages", ['package_ids' => [$this->own->id]])
        ->assertOk();

    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeFalse()
        ->and($this->registry->packages()->whereKey($this->own->id)->exists())->toBeTrue();
});

it('still lets a customer admin replace their own packages through the api', function () {
    $other = Package::factory()->for($this->customer)->create(['type' => 'composer', 'name' => 'kunde/zweit']);
    $this->registry->packages()->attach($this->own->id);

    $this->withToken(authorityKeyFor($this->customerAdmin))
        ->putJson("/api/v1/groups/{$this->registry->id}/packages", ['package_ids' => [$other->id]])
        ->assertOk();

    expect($this->registry->packages()->whereKey($other->id)->exists())->toBeTrue()
        ->and($this->registry->packages()->whereKey($this->own->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------
// The console page must not offer the two row actions it would then refuse — the same
// defect as the picker offering what the guard rejects, on the other side of the page.
// ---------------------------------------------------------------------------------------

it('tells the console that a customer admin may not manage a shared assignment', function () {
    $this->registry->packages()->attach([$this->shared->id, $this->own->id]);

    // Ordered by name: `betrieb/geteilt` before `kunde/eigen`.
    $this->actingAs($this->customerAdmin)
        ->get(route('admin.groups.show', $this->registry))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('packages.0.shared', true)
            ->where('packages.0.manageable', false)
            ->where('packages.1.shared', false)
            ->where('packages.1.manageable', true)
            ->etc());
});

it('tells the console that someone administering the owner may manage a shared assignment', function () {
    $this->registry->packages()->attach($this->shared->id);

    $this->actingAs(operatorStaff($this->customer, $this->operator))
        ->get(route('admin.groups.show', $this->registry))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('packages.0.manageable', true)->etc());
});
