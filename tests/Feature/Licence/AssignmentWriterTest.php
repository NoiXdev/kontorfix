<?php

/*
 * AssignmentWriter — the single writer of `group_package.available_until`, `version_min`
 * and `version_max` (see Group::assignedPackages()'s docblock). Every one of its three
 * methods carries the full guarantee itself (fix round 1 pulled the last two guards in
 * from the controller that used to ask them on the writer's behalf):
 *
 *   - the caller administers the TARGET GROUP's organization at all
 *     (assertAdministersGroupInScope);
 *   - a SHARED package's assignment may only be created/edited/ended by someone who
 *     administers the OWNING organization (assertMayEditSharedAssignment); anything else
 *     (an own package) only requires assertCanTouchPackage — ownership within the
 *     caller's active console scope;
 *   - the resulting assignment does not shadow, and is not shadowed by, a same-named
 *     package of the other kind (SharedAssignment::assertAssignable(), run on EVERY
 *     write() and assign(), not only ones that change membership);
 *   - (write()/assign() only) the EFFECTIVE bounds — the merged, post-controller-preserve
 *     pair actually about to be persisted, not merely what one caller's request
 *     submitted — are syntactically valid for the package's type, ordered, and absent for
 *     a Docker package.
 *
 * `write()` and `revoke()` operate on an existing pivot row; `assign()` creates one and so
 * also asks the reachability question (own-org or shared) a fresh row still has to answer.
 *
 * The bounds fix is pinned directly: two tests below submit only ONE side of a pair against
 * a STORED value on the other side that makes the merged pair impossible (`min > max`) —
 * the exact shape of the incident this replaced, where a check run on the raw, pre-merge
 * request missed it. The remaining bounds SHAPE-only rules (presence, string, length) live
 * in AssignmentBoundsRequest so both admin surfaces share them — exercised here through
 * Admin\GroupController::updateAssignment(), the one route currently wired to it, since a
 * FormRequest's rules() are never tested standalone in this codebase.
 */

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\GroupPackage;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use App\Services\Package\AssignmentWriter;
use App\Support\Licence\VersionBounds;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->customer = Organization::factory()->create(['is_operator' => false]);
    $this->registry = Group::factory()->for($this->customer)->create();

    $this->customerAdmin = User::factory()->for($this->customer)->create(['role' => UserRole::Admin]);

    $this->shared = Package::factory()->for($this->operator)
        ->create(['type' => 'composer', 'name' => 'betrieb/geteilt', 'shared' => true]);
    $this->own = Package::factory()->for($this->customer)
        ->create(['type' => 'composer', 'name' => 'kunde/eigen']);
});

/** An account administering both organizations — see SharedAssignmentAuthorityTest. */
function writerOperatorStaff(Organization $customer, Organization $operator): User
{
    $user = User::factory()->for($customer)->create(['role' => UserRole::Admin]);
    $user->organizations()->attach($operator->id, ['role' => UserRole::Maintainer->value]);

    return $user;
}

/** The pivot row itself. */
function writerAssignmentOf(Group $group, Package $package): ?GroupPackage
{
    return GroupPackage::where('group_id', $group->id)->where('package_id', $package->id)->first();
}

// -----------------------------------------------------------------------------------------
// write() — bounds + period on an EXISTING assignment.
// -----------------------------------------------------------------------------------------

it('writes bounds and period through the service, persisting both', function () {
    $this->registry->packages()->attach($this->own->id);

    $this->actingAs($this->customerAdmin);

    app(AssignmentWriter::class)->write(
        $this->registry,
        $this->own,
        CarbonImmutable::parse('2027-06-30')->endOfDay(),
        new VersionBounds('2.0.0', '3.0.0'),
    );

    $row = writerAssignmentOf($this->registry, $this->own);

    expect($row->available_until->toDateString())->toBe('2027-06-30')
        ->and($row->version_min)->toBe('2.0.0')
        ->and($row->version_max)->toBe('3.0.0');
});

it('refuses a customer admin writing bounds on a shared package, leaving the row untouched', function () {
    $this->registry->packages()->attach($this->shared->id, ['available_until' => null]);

    $this->actingAs($this->customerAdmin);

    $thrown = fn () => app(AssignmentWriter::class)->write(
        $this->registry,
        $this->shared,
        CarbonImmutable::parse('2027-06-30')->endOfDay(),
        new VersionBounds('2.0.0', '3.0.0'),
    );

    // @phpstan-ignore-next-line argument.type (Pest's stub is stricter than what it actually accepts at runtime)
    expect($thrown)->toThrow(function (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    });

    $row = writerAssignmentOf($this->registry, $this->shared);
    expect($row->available_until)->toBeNull()
        ->and($row->version_min)->toBeNull()
        ->and($row->version_max)->toBeNull();
});

it('lets someone who administers the owning organization write bounds on a shared package', function () {
    $this->registry->packages()->attach($this->shared->id, ['available_until' => null]);

    $this->actingAs(writerOperatorStaff($this->customer, $this->operator));

    app(AssignmentWriter::class)->write(
        $this->registry,
        $this->shared,
        null,
        new VersionBounds('2.0.0', null),
    );

    $row = writerAssignmentOf($this->registry, $this->shared);
    expect($row->version_min)->toBe('2.0.0')
        ->and($row->version_max)->toBeNull();
});

// -----------------------------------------------------------------------------------------
// assign() — creates the pivot row.
// -----------------------------------------------------------------------------------------

it('creates the pivot row through assign(), with period and bounds set at grant time', function () {
    $this->actingAs($this->customerAdmin);

    app(AssignmentWriter::class)->assign(
        $this->registry,
        $this->own,
        CarbonImmutable::parse('2027-01-01')->endOfDay(),
        new VersionBounds('1.0.0', '2.0.0'),
    );

    $row = writerAssignmentOf($this->registry, $this->own);
    expect($row)->not->toBeNull()
        ->and($row->version_min)->toBe('1.0.0')
        ->and($row->version_max)->toBe('2.0.0')
        ->and($row->available_until->toDateString())->toBe('2027-01-01');
});

it('lets someone who administers the owning organization assign a shared package with bounds', function () {
    $this->actingAs(writerOperatorStaff($this->customer, $this->operator));

    app(AssignmentWriter::class)->assign($this->registry, $this->shared, null, VersionBounds::unlimited());

    expect(writerAssignmentOf($this->registry, $this->shared))->not->toBeNull();
});

it('refuses a customer admin assigning a shared package via assign(), creating no row', function () {
    $this->actingAs($this->customerAdmin);

    $thrown = fn () => app(AssignmentWriter::class)->assign(
        $this->registry,
        $this->shared,
        null,
        VersionBounds::unlimited(),
    );

    // @phpstan-ignore-next-line argument.type (Pest's stub is stricter than what it actually accepts at runtime)
    expect($thrown)->toThrow(function (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    });

    expect(writerAssignmentOf($this->registry, $this->shared))->toBeNull();
});

it('refuses assigning a package owned outside the target registrys organization and not shared', function () {
    $foreign = Package::factory()->create(['type' => 'composer', 'name' => 'fremd/paket', 'shared' => false]);

    $this->actingAs($this->customerAdmin);

    $thrown = fn () => app(AssignmentWriter::class)->assign(
        $this->registry,
        $foreign,
        null,
        VersionBounds::unlimited(),
    );

    // @phpstan-ignore-next-line argument.type (Pest's stub is stricter than what it actually accepts at runtime)
    expect($thrown)->toThrow(function (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    });

    expect(writerAssignmentOf($this->registry, $foreign))->toBeNull();
});

// -----------------------------------------------------------------------------------------
// revoke() — ends an assignment outright.
// -----------------------------------------------------------------------------------------

it('removes the row through revoke()', function () {
    $this->registry->packages()->attach($this->own->id, ['version_min' => '1.0.0']);

    $this->actingAs($this->customerAdmin);

    app(AssignmentWriter::class)->revoke($this->registry, $this->own);

    expect(writerAssignmentOf($this->registry, $this->own))->toBeNull();
});

it('refuses a customer admin revoking a shared assignment, leaving the row in place', function () {
    $this->registry->packages()->attach($this->shared->id);

    $this->actingAs($this->customerAdmin);

    $thrown = fn () => app(AssignmentWriter::class)->revoke($this->registry, $this->shared);

    // @phpstan-ignore-next-line argument.type (Pest's stub is stricter than what it actually accepts at runtime)
    expect($thrown)->toThrow(function (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    });

    expect(writerAssignmentOf($this->registry, $this->shared))->not->toBeNull();
});

// -----------------------------------------------------------------------------------------
// Bounds validation — AssignmentBoundsRequest, exercised through the one wired route.
// -----------------------------------------------------------------------------------------

it('refuses min >= max on the update route, writing nothing', function () {
    $this->registry->packages()->attach($this->own->id);

    $this->actingAs($this->customerAdmin)
        ->put(route('admin.groups.packages.update', [$this->registry, $this->own]), [
            'available_until' => null,
            'version_min' => '3.0.0',
            'version_max' => '2.0.0',
        ])
        // A plain console PUT (no Accept: application/json) redirects with flashed
        // validation errors — 302, not 422; every FormRequest failure in this app's admin
        // console asserts this way, see e.g. tests/Feature/Admin/GroupCrudTest.php.
        ->assertSessionHasErrors(['version_min' => 'Die Untergrenze muss kleiner als die Obergrenze sein.']);

    $row = writerAssignmentOf($this->registry, $this->own);
    expect($row->version_min)->toBeNull()
        ->and($row->version_max)->toBeNull();
});

it('refuses any bound on a Docker package, writing nothing', function () {
    $docker = Package::factory()->for($this->customer)->docker()->create(['name' => 'kunde/image']);
    $this->registry->packages()->attach($docker->id);

    $this->actingAs($this->customerAdmin)
        ->put(route('admin.groups.packages.update', [$this->registry, $docker]), [
            'available_until' => null,
            'version_min' => '1.0',
        ])
        ->assertSessionHasErrors(['version_min' => 'Für Docker-Pakete gibt es keine Versionsgrenzen.']);

    $row = writerAssignmentOf($this->registry, $docker);
    expect($row->version_min)->toBeNull();
});

it('refuses a syntactically invalid Composer bound, writing nothing', function () {
    $this->registry->packages()->attach($this->own->id);

    $this->actingAs($this->customerAdmin)
        ->put(route('admin.groups.packages.update', [$this->registry, $this->own]), [
            'available_until' => null,
            'version_min' => 'not-a-version!!',
        ])
        ->assertSessionHasErrors(['version_min' => 'Diese Version ist syntaktisch ungültig.']);

    $row = writerAssignmentOf($this->registry, $this->own);
    expect($row->version_min)->toBeNull();
});

it('refuses a syntactically invalid PyPI bound, writing nothing', function () {
    $python = Package::factory()->for($this->customer)->create(['type' => 'python', 'name' => 'kunde-python']);
    $this->registry->packages()->attach($python->id);

    $this->actingAs($this->customerAdmin)
        ->put(route('admin.groups.packages.update', [$this->registry, $python]), [
            'available_until' => null,
            'version_min' => 'not!a!pep440!version',
        ])
        ->assertSessionHasErrors(['version_min' => 'Diese Version ist syntaktisch ungültig.']);

    $row = writerAssignmentOf($this->registry, $python);
    expect($row->version_min)->toBeNull();
});

it('accepts a valid PyPI bound pair with min < max under PEP 440 ordering', function () {
    $python = Package::factory()->for($this->customer)->create(['type' => 'python', 'name' => 'kunde-python-2']);
    $this->registry->packages()->attach($python->id);

    $this->actingAs($this->customerAdmin)
        ->put(route('admin.groups.packages.update', [$this->registry, $python]), [
            'available_until' => null,
            'version_min' => '1.0.dev1',
            'version_max' => '1.0',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $row = writerAssignmentOf($this->registry, $python);
    expect($row->version_min)->toBe('1.0.dev1')
        ->and($row->version_max)->toBe('1.0');
});

it('preserves the existing bounds when the update route submits only the date', function () {
    $this->registry->packages()->attach($this->own->id, ['version_min' => '1.0.0', 'version_max' => '2.0.0']);

    $this->actingAs($this->customerAdmin)
        ->put(route('admin.groups.packages.update', [$this->registry, $this->own]), [
            'available_until' => '2027-05-01',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $row = writerAssignmentOf($this->registry, $this->own);
    expect($row->version_min)->toBe('1.0.0')
        ->and($row->version_max)->toBe('2.0.0')
        ->and($row->available_until->toDateString())->toBe('2027-05-01');
});

it('refuses an omitted version_min that would leave an impossible window against the stored one, writing nothing', function () {
    // Stored min is 5.0.0. Submitting only version_max=3.0.0 (min omitted) must validate
    // the EFFECTIVE pair after the controller's "omitted = keep stored" merge — 5.0.0 is
    // not < 3.0.0, an impossible window that admits no version at all.
    $this->registry->packages()->attach($this->own->id, ['version_min' => '5.0.0']);

    $this->actingAs($this->customerAdmin)
        ->put(route('admin.groups.packages.update', [$this->registry, $this->own]), [
            'available_until' => null,
            'version_max' => '3.0.0',
        ])
        ->assertSessionHasErrors(['version_min' => 'Die Untergrenze muss kleiner als die Obergrenze sein.']);

    $row = writerAssignmentOf($this->registry, $this->own);
    expect($row->version_min)->toBe('5.0.0')
        ->and($row->version_max)->toBeNull();
});

it('refuses an omitted version_max that would leave an impossible window against the stored one, writing nothing', function () {
    // The symmetric case: stored max is 2.0.0, submitted min (3.0.0, max omitted) is above
    // it once merged.
    $this->registry->packages()->attach($this->own->id, ['version_max' => '2.0.0']);

    $this->actingAs($this->customerAdmin)
        ->put(route('admin.groups.packages.update', [$this->registry, $this->own]), [
            'available_until' => null,
            'version_min' => '3.0.0',
        ])
        ->assertSessionHasErrors(['version_min' => 'Die Untergrenze muss kleiner als die Obergrenze sein.']);

    $row = writerAssignmentOf($this->registry, $this->own);
    expect($row->version_min)->toBeNull()
        ->and($row->version_max)->toBe('2.0.0');
});

// -----------------------------------------------------------------------------------------
// Fix round 1 — guards the reviewer found missing from the service itself.
// -----------------------------------------------------------------------------------------

it('refuses assign() creating a shadowing shared assignment, called directly with no HTTP layer', function () {
    // The registry already serves its own package under a name; assigning a SHARED
    // package of the SAME type+name would let the operator's package shadow it — the
    // collision SharedAssignment exists to refuse, checked directly at the service level
    // (no controller in between) so a future caller cannot reach assign() without it.
    $this->registry->packages()->attach($this->own->id);

    $shadowing = Package::factory()->for($this->operator)
        ->create(['type' => $this->own->type, 'name' => $this->own->name, 'shared' => true]);

    $this->actingAs(writerOperatorStaff($this->customer, $this->operator));

    $thrown = fn () => app(AssignmentWriter::class)->assign(
        $this->registry,
        $shadowing,
        null,
        VersionBounds::unlimited(),
    );

    expect($thrown)->toThrow(ValidationException::class);

    expect(writerAssignmentOf($this->registry, $shadowing))->toBeNull();
});

it('refuses write() by an account with no relationship to the target group, even administering the shared packages owner', function () {
    // Administering the OWNING organization of a shared package is not administering
    // every registry it happens to be shared into — the target group is a separate
    // question the service must ask itself, not rely on the controller to have asked.
    $this->registry->packages()->attach($this->shared->id, ['available_until' => null]);

    // Maintainer, not Admin: an Admin whose home org is the operator organization is the
    // grandfathered super-admin (User::isSuperAdmin()) and would administer every
    // organization, including the target group — defeating the very thing this test
    // means to isolate.
    $operatorOnlyAdmin = User::factory()->for($this->operator)->create(['role' => UserRole::Maintainer]);
    $this->actingAs($operatorOnlyAdmin);

    $thrown = fn () => app(AssignmentWriter::class)->write(
        $this->registry,
        $this->shared,
        CarbonImmutable::parse('2027-06-30')->endOfDay(),
        VersionBounds::unlimited(),
    );

    // @phpstan-ignore-next-line argument.type (Pest's stub is stricter than what it actually accepts at runtime)
    expect($thrown)->toThrow(function (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    });

    $row = writerAssignmentOf($this->registry, $this->shared);
    expect($row->available_until)->toBeNull();
});
