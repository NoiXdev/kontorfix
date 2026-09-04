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
 * The rule, in two sentences, because spec §4's sentence has two halves and only the first
 * is about which packages a registry carries:
 *
 *   1. the shared assignments a caller may not manage must be the SAME before and after
 *      the write; and
 *   2. editing such an assignment's availability requires administering the owner.
 *
 * The first is stated over the RESULTING SET rather than over the operation, the same shape
 * SharedAssignment uses and for the same reason: a rule phrased as "does this write add a
 * shared package" has a direction and forgets the reverse — a `sync()` that DROPS one adds
 * nothing and satisfies it. A set comparison covers attach, detach, replace and swap at once,
 * and it says exactly what the spec says and nothing more: a write that leaves the shared
 * assignments as they were neither attaches nor edits anything, so it is not refused. That
 * matters for the API's PUT, whose submission is the whole post-state — under a rule stated
 * over the submission, a customer admin could neither name the shared package (an attach) nor
 * omit it (a detach), and the endpoint was unusable to them.
 *
 * The second cannot be derived from the first and is not left to hold by accident:
 * `available_until` is a column on the pivot row, so re-dating leaves the resulting SET
 * identical and passes that comparison untouched. It is asked by its own guard, and the test
 * "it refuses re-dating even the shared package this customer admin may re-submit" below
 * pins the distinction in one place.
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
    // A second shared package, so "the set changed" can be told apart from "the set grew or
    // shrank": swapping one for the other leaves the count alone.
    $this->otherShared = Package::factory()->for($this->operator)
        ->create(['type' => 'composer', 'name' => 'betrieb/zweit', 'shared' => true]);
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

// ---------------------------------------------------------------------------------------
// The three cases of the set rule, on both surfaces. The middle one is the whole point: a
// write that leaves the shared assignments as they were is not an assignment being made, so
// refusing it would enforce something spec §4 does not ask for — and, on the API's `sync()`,
// would leave the customer no way to send a package list at all.
// ---------------------------------------------------------------------------------------

it('lets a customer admin submit the shared package their registry already carries', function () {
    // UNCHANGED. The submission names the shared package, which a rule stated over the
    // submission would refuse; the resulting shared set is `{shared}` either way, so nothing
    // is being assigned and nothing is refused. The own package is added as it always was.
    $this->registry->packages()->attach($this->shared->id);

    $this->actingAs($this->customerAdmin)
        ->post(route('admin.groups.packages.store', $this->registry), [
            'package_ids' => [$this->shared->id, $this->own->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeTrue()
        ->and($this->registry->packages()->whereKey($this->own->id)->exists())->toBeTrue();
});

it('refuses a customer admin adding a second shared package beside the one they carry', function () {
    // GROWN, with a shared package already present — so the refusal cannot come from "any
    // shared package in the submission" and has to come from the comparison.
    $this->registry->packages()->attach($this->shared->id);

    $this->actingAs($this->customerAdmin)
        ->post(route('admin.groups.packages.store', $this->registry), [
            'package_ids' => [$this->shared->id, $this->otherShared->id],
        ])
        ->assertForbidden();

    expect($this->registry->packages()->whereKey($this->otherShared->id)->exists())->toBeFalse();
});

it('lets a customer admin re-send the shared package their registry already carries through the api', function () {
    // UNCHANGED, on the surface it matters for: `sync()` makes the submission the whole
    // post-state, so this is the only way a customer admin can manage their own list at all
    // once the operator has placed a shared package in their registry.
    $this->registry->packages()->attach([$this->shared->id, $this->own->id]);
    $other = Package::factory()->for($this->customer)->create(['type' => 'composer', 'name' => 'kunde/zweit']);

    $this->withToken(authorityKeyFor($this->customerAdmin))
        ->putJson("/api/v1/groups/{$this->registry->id}/packages", [
            'package_ids' => [$this->shared->id, $other->id],
        ])
        ->assertOk();

    // Their own list changed exactly as asked; the shared assignment is untouched.
    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeTrue()
        ->and($this->registry->packages()->whereKey($other->id)->exists())->toBeTrue()
        ->and($this->registry->packages()->whereKey($this->own->id)->exists())->toBeFalse();
});

it('refuses a customer admin swapping one shared package for another through the api', function () {
    // CHANGED WITHOUT GROWING OR SHRINKING. One in, one out: the count is identical on both
    // sides, so only an identity comparison refuses this. It is also the write that would let
    // a customer trade the package the operator gave them for one meant for someone else.
    $this->registry->packages()->attach($this->shared->id);

    $this->withToken(authorityKeyFor($this->customerAdmin))
        ->putJson("/api/v1/groups/{$this->registry->id}/packages", [
            'package_ids' => [$this->otherShared->id],
        ])
        ->assertForbidden();

    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeTrue()
        ->and($this->registry->packages()->whereKey($this->otherShared->id)->exists())->toBeFalse();
});

it('lets someone who administers the owning organization swap one shared package for another', function () {
    // The same write, allowed for the operator — so the refusal above is about the caller and
    // not about swaps being forbidden in general.
    $this->registry->packages()->attach($this->shared->id);

    $this->withToken(authorityKeyFor(operatorStaff($this->customer, $this->operator)))
        ->putJson("/api/v1/groups/{$this->registry->id}/packages", [
            'package_ids' => [$this->otherShared->id],
        ])
        ->assertOk();

    expect($this->registry->packages()->whereKey($this->otherShared->id)->exists())->toBeTrue()
        ->and($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeFalse();
});

it('refuses re-dating even the shared package this customer admin may re-submit', function () {
    // The distinction between the two halves of the rule, in one place. The SAME actor and
    // the SAME package: submitting it leaves the assignment set identical and is accepted,
    // while re-dating it changes the row and is refused. A single set comparison cannot tell
    // these apart — `available_until` is not in the set — so if the availability guard were
    // ever dropped on the assumption that the set rule covers it, this fails.
    $this->registry->packages()->attach($this->shared->id, ['available_until' => null]);

    $this->actingAs($this->customerAdmin)
        ->post(route('admin.groups.packages.store', $this->registry), ['package_ids' => [$this->shared->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($this->customerAdmin)
        ->put(route('admin.groups.packages.update', [$this->registry, $this->shared]), [
            'available_until' => '2027-12-31',
        ])
        ->assertForbidden();

    expect(assignmentOf($this->registry, $this->shared)?->available_until)->toBeNull();
});

// ---------------------------------------------------------------------------------------
// A LAPSED shared assignment is still the operator's. The "before" set is read from
// packages(), not assignedPackages(): if it were read from what the registry SERVES, a
// lapsed row would be invisible on both sides of the comparison and dropping it would look
// like no change at all. That is the worst version of this hole rather than the mildest —
// per spec §4 as amended, a lapsed assignment stops delivery but keeps the name suppressed
// against the upstream, and DETACHING is the one act that releases it. A customer admin who
// could detach a lapsed row could therefore reopen a private name to the public index for
// their own builds, which is precisely the substitution the whole feature exists to prevent.
// ---------------------------------------------------------------------------------------

it('refuses a customer admin detaching a lapsed shared assignment', function () {
    $this->registry->packages()->attach($this->shared->id, ['available_until' => now()->subDay()]);

    $this->actingAs($this->customerAdmin)
        ->delete(route('admin.groups.packages.destroy', [$this->registry, $this->shared]))
        ->assertForbidden();

    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeTrue();
});

it('refuses a customer admin dropping a lapsed shared assignment through the api', function () {
    $this->registry->packages()->attach($this->own->id);
    $this->registry->packages()->attach($this->shared->id, ['available_until' => now()->subDay()]);

    $this->withToken(authorityKeyFor($this->customerAdmin))
        ->putJson("/api/v1/groups/{$this->registry->id}/packages", ['package_ids' => [$this->own->id]])
        ->assertForbidden();

    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeTrue();
});

it('lets someone who administers the owning organization detach a lapsed shared assignment', function () {
    // The other direction, so the rule above is about the caller and not about lapsed rows
    // being frozen: withdrawing a share for good is exactly what the operator does here.
    $this->registry->packages()->attach($this->shared->id, ['available_until' => now()->subDay()]);

    $this->actingAs(operatorStaff($this->customer, $this->operator))
        ->delete(route('admin.groups.packages.destroy', [$this->registry, $this->shared]))
        ->assertRedirect();

    expect($this->registry->packages()->whereKey($this->shared->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------
// The permitted re-submission must stay a NO-OP on the pivot row, not merely on membership.
//
// A customer admin is allowed to name a shared package their registry already carries,
// because the set is unchanged and nothing is being assigned. That is only harmless while
// `sync()` and `syncWithoutDetaching()` are handed a FLAT LIST OF IDS: given one they insert
// the missing rows and delete the extra ones and touch no columns on the rest. Handed pivot
// attributes instead they update every named row — and `assertSharedAssignmentsUnchanged()`
// would still see an unchanged set while the write re-dated a shared assignment, which is the
// second half of spec §4 reached through the first, by the one caller the first half permits.
//
// `group_package.version_constraint` already exists and is unused, so the fuse is in the
// schema and only the endpoint is missing. These two fail the moment a write path starts
// passing attributes, on whichever surface does it first.
// ---------------------------------------------------------------------------------------

it('does not rewrite the availability of a re-submitted shared assignment', function () {
    $this->registry->packages()->attach($this->shared->id, ['available_until' => now()->addYear()]);
    $until = assignmentOf($this->registry, $this->shared)?->available_until?->toDateTimeString();

    $this->actingAs($this->customerAdmin)
        ->post(route('admin.groups.packages.store', $this->registry), [
            'package_ids' => [$this->shared->id, $this->own->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Accepted, and the operator's date is exactly where the operator left it.
    expect(assignmentOf($this->registry, $this->shared)?->available_until?->toDateTimeString())->toBe($until);
});

it('does not rewrite the availability of a re-submitted shared assignment through the api', function () {
    $this->registry->packages()->attach($this->shared->id, ['available_until' => now()->addYear()]);
    $until = assignmentOf($this->registry, $this->shared)?->available_until?->toDateTimeString();

    $this->withToken(authorityKeyFor($this->customerAdmin))
        ->putJson("/api/v1/groups/{$this->registry->id}/packages", [
            'package_ids' => [$this->shared->id, $this->own->id],
        ])
        ->assertOk();

    expect(assignmentOf($this->registry, $this->shared)?->available_until?->toDateTimeString())->toBe($until);
});
