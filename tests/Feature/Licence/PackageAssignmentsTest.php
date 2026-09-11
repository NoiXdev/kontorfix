<?php

/*
 * Task 8: the "Freigaben" tab on the package page — the operator-facing surface over
 * {@see App\Services\Package\AssignmentWriter}, reached from the PACKAGE side rather than
 * the registry side `Admin\GroupController` already offers those same three writes from.
 *
 * `Admin\PackageController::show()` gains three payload keys: `assignments` (grouped by
 * customer in the UI), `can_manage_assignments` (the tab's master switch) and
 * `major_lines` (the bounds editor's "Nur eine Hauptversion" dropdown). This file pins
 * three things about that payload specifically:
 *
 *  1. A caller who may manage a shared package's assignments sees every one of them,
 *     across every customer registry it is assigned to.
 *  2. A caller who may NOT — a customer whose own registry merely RECEIVES the package —
 *     sees `can_manage_assignments: false` and an EMPTY `assignments` array. Not a
 *     filtered one: this is the one payload key on the page that would otherwise name
 *     another customer's registry to a viewer with no business reading it, so the rule is
 *     "nothing at all", not "only your own row".
 *  3. Every refusal on the three write routes (store/update/destroy) leaves the database
 *     exactly as it was — a 403 that already mutated something is worse than no guard.
 *
 * The rest (store creates, update changes, destroy removes) is the ordinary CRUD path
 * over a service ({@see App\Services\Package\AssignmentWriter}) this file does not
 * re-validate the internals of — AssignmentWriterTest already does that exhaustively.
 * This file only pins that PackageAssignmentController hands the writer what it asked for,
 * from the package side.
 */

use App\Enums\UserRole;
use App\Models\GitCredential;
use App\Models\Group;
use App\Models\GroupPackage;
use App\Models\MirrorSource;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\PythonDist;
use App\Models\User;

function assignmentRowOf(Group $group, Package $package): ?GroupPackage
{
    return GroupPackage::where('group_id', $group->id)->where('package_id', $package->id)->first();
}

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->customerA = Organization::factory()->create(['name' => 'Kunde A']);
    $this->customerB = Organization::factory()->create(['name' => 'Kunde B']);
    $this->registryA = Group::factory()->for($this->customerA)->create(['name' => 'Registry A']);
    $this->registryB = Group::factory()->for($this->customerB)->create(['name' => 'Registry B']);

    $this->customerAdminA = User::factory()->for($this->customerA)->create(['role' => UserRole::Admin]);
    $this->customerAdminB = User::factory()->for($this->customerB)->create(['role' => UserRole::Admin]);

    $this->shared = Package::factory()->for($this->operator)
        ->create(['type' => 'composer', 'name' => 'betrieb/geteilt', 'shared' => true]);
    $this->own = Package::factory()->for($this->customerA)
        ->create(['type' => 'composer', 'name' => 'kunde/eigen']);
});

// -----------------------------------------------------------------------------------------
// show() — the payload: who sees what.
// -----------------------------------------------------------------------------------------

it('lists every customers assignment of a shared package, grouped by organization, for a caller who may manage it', function () {
    $this->registryA->packages()->attach($this->shared->id, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);
    $this->registryB->packages()->attach($this->shared->id, ['available_until' => now()->addDays(10)]);

    $this->actingAs(superAdmin())->get(route('admin.packages.show', $this->shared))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_manage_assignments', true)
            ->has('assignments', 2)
            // Sorted by organization name, so Kunde A precedes Kunde B.
            ->where('assignments.0.organization_name', 'Kunde A')
            ->where('assignments.0.group_name', 'Registry A')
            ->where('assignments.0.version_min', '2.0.0')
            ->where('assignments.0.version_max', '3.0.0')
            ->where('assignments.0.in_force', true)
            ->where('assignments.1.organization_name', 'Kunde B')
            ->where('assignments.1.group_name', 'Registry B')
            ->where('assignments.1.version_min', null)
            ->where('assignments.1.available_until', now()->addDays(10)->toDateString())
            ->etc());
});

it('lets an own packages owning organization manage its assignments and see them', function () {
    $this->registryA->packages()->attach($this->own->id);

    $this->actingAs($this->customerAdminA)->get(route('admin.packages.show', $this->own))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_manage_assignments', true)
            ->has('assignments', 1)
            ->where('assignments.0.group_name', 'Registry A')
            ->etc());
});

it('hides every assignment row from a customer who only receives a shared package, and marks it unmanageable', function () {
    $this->registryA->packages()->attach($this->shared->id, ['version_min' => '2.0.0']);
    $this->registryB->packages()->attach($this->shared->id);

    $response = $this->actingAs($this->customerAdminA)->get(route('admin.packages.show', $this->shared))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_manage_assignments', false)
            ->where('assignments', [])
            ->etc());

    // The payload must never expose another customer's data to a caller who may not see
    // it — not even the fact that Kunde B's registry exists.
    $response->assertDontSee('Kunde B')->assertDontSee('Registry B');
});

it('withholds every operator-internal detail from a customer who only receives a shared package', function () {
    // The widened viewing guard (assertCanViewPackage()) lets this caller reach show() at
    // all — a customer whose OWN registry carries a shared package. That is deliberate.
    // What follows is NOT: the fixtures below are all things that exist ONLY on the
    // operator's side of a package this viewer never touches beyond receiving it — a git
    // credential, an activity trail naming an OPERATOR EMPLOYEE, and a repository URL —
    // and the assertions below prove they are gone from the RESPONSE, not merely hidden
    // by a `v-if` an Inertia payload still carries in full underneath. A prior version of
    // this test asserted none of this and stayed green while every one of these leaked.
    $employee = User::factory()->for($this->operator)->create(['name' => 'Betrieb Mitarbeiterin']);
    $credential = GitCredential::factory()->for($this->operator)->create(['name' => 'Geheimes Operator-Credential']);

    $this->actingAs($employee);
    $this->shared->update([
        // `repository_url` is loggable per Package::getActivitylogOptions() — this write
        // is what puts the employee's name into the activity trail as `causer`, and the
        // new URL into its logged `changes`. `git_credential_id` is not itself loggable;
        // it is exercised through the `package.git_credential_id` payload key instead.
        'repository_url' => 'https://github.com/betrieb/geteilt-intern.git',
        'git_credential_id' => $credential->id,
    ]);
    // A per-version usage figure — the same cross-customer number `stats.downloads` sums.
    // Nulling only the sum while leaving this would let the customer reconstruct exactly
    // what was withheld, and the Versionen tab renders it directly regardless.
    PackageVersion::factory()->for($this->shared)->create(['download_count' => 4321, 'dist_size' => 555]);

    $this->registryA->packages()->attach($this->shared->id, ['version_min' => '2.0.0']);
    $this->registryB->packages()->attach($this->shared->id);

    $response = $this->actingAs($this->customerAdminA)->get(route('admin.packages.show', $this->shared))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_manage_assignments', false)
            ->where('assignments', [])
            ->where('gitCredentials', [])
            ->where('mirrorSources', null)
            ->where('activities', [])
            ->where('sharedElsewhere', 0)
            ->where('package.repository_url', null)
            ->where('package.git_credential_id', null)
            ->where('package.has_repository_token', false)
            ->where('package.sync_error', null)
            ->where('versions.0.download_count', null)
            ->where('versions.0.dist_size', null)
            ->where('versions.0.reference', null)
            ->etc());

    $response
        ->assertDontSee('Kunde B')
        ->assertDontSee('Registry B')
        ->assertDontSee('Geheimes Operator-Credential')
        ->assertDontSee('Betrieb Mitarbeiterin')
        // Not `assertDontSee('betrieb/geteilt-intern')`: Inertia's JSON response escapes
        // `/` as `\/`, so a leaked URL containing one would never match that string — a
        // slash-free substring is what would actually catch it reappearing.
        ->assertDontSee('geteilt-intern');
    // download_count/dist_size are proven absent structurally above (`versions.0.…` is
    // `null`, not merely equal to some OTHER value) — a raw-text assertDontSee() for a
    // short generic number like `555` is not a stronger check and is prone to a false
    // failure the moment that digit sequence appears anywhere else on the page by chance.
});

it('still shows real per-version download and storage figures to a caller who may manage the package', function () {
    // The companion to the test above: proving the trim withholds these figures from a
    // non-manager is only half the guarantee. This proves it does not ALSO withhold them
    // from the operator who is supposed to see them — a fix that zeroed the field
    // unconditionally would pass every "withholds…" test and still be wrong.
    PackageVersion::factory()->for($this->shared)->create(['download_count' => 4321, 'dist_size' => 555, 'source_reference' => 'abc123def']);

    $this->actingAs(superAdmin())->get(route('admin.packages.show', $this->shared))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_manage_assignments', true)
            ->where('versions.0.download_count', 4321)
            ->where('versions.0.dist_size', 555)
            ->where('versions.0.reference', 'abc123def')
            ->etc());
});

it('withholds pythonDists download and size figures from a customer who only receives a shared python package', function () {
    $pythonPackage = Package::factory()->for($this->operator)
        ->create(['type' => 'python', 'name' => 'betrieb-geteilt-python', 'shared' => true]);
    PythonDist::factory()->for($pythonPackage)->create(['download_count' => 999, 'size' => 12345]);
    $this->registryA->packages()->attach($pythonPackage->id);

    $this->actingAs($this->customerAdminA)->get(route('admin.packages.show', $pythonPackage))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_manage_assignments', false)
            ->where('pythonDists.0.download_count', null)
            ->where('pythonDists.0.size', null)
            ->etc());
});

it('still shows real pythonDists download and size figures to a caller who may manage the package', function () {
    $pythonPackage = Package::factory()->for($this->operator)
        ->create(['type' => 'python', 'name' => 'betrieb-geteilt-python-2', 'shared' => true]);
    PythonDist::factory()->for($pythonPackage)->create(['download_count' => 999, 'size' => 12345]);
    $this->registryA->packages()->attach($pythonPackage->id);

    $this->actingAs(superAdmin())->get(route('admin.packages.show', $pythonPackage))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_manage_assignments', true)
            ->where('pythonDists.0.download_count', 999)
            ->where('pythonDists.0.size', 12345)
            ->etc());
});

it('withholds the mirror source from a customer who only receives a mirror-sourced shared package', function () {
    $mirrorSource = MirrorSource::factory()->for($this->operator)->create(['name' => 'Geheime Mirror-Quelle']);
    $mirrorPackage = Package::factory()->for($this->operator)->create([
        'type' => 'composer',
        'name' => 'betrieb/mirror-paket',
        'shared' => true,
        'source_mode' => 'mirror',
        'mirror_source_id' => $mirrorSource->id,
        'mirror_name' => 'upstream/paket',
    ]);
    $this->registryA->packages()->attach($mirrorPackage->id);

    $response = $this->actingAs($this->customerAdminA)->get(route('admin.packages.show', $mirrorPackage))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('package.mirror', null)
            ->where('mirrorSources', null)
            ->etc());

    $response->assertDontSee('Geheime Mirror-Quelle');
});

// -----------------------------------------------------------------------------------------
// Per-row `can_edit` — `can_manage_assignments` is a PACKAGE-level answer
// (assertMayTouchAssignment's own question), but AssignmentWriter's write()/assign()/
// revoke() ALSO ask assertAdministersGroupInScope() per row, which reads the caller's
// ACTIVE SCOPE — narrower than the full administered set canManageAssignments() checks.
// A caller who administers the shared package's owner but has scoped the console down to
// one customer organization sees `can_manage_assignments: true` yet would be refused
// editing a DIFFERENT customer's row — the UI must not offer what the writer refuses.
// -----------------------------------------------------------------------------------------

it('marks a row uneditable when it falls outside the callers active scope, even though the package itself is manageable', function () {
    // Administers both the operator (Maintainer, avoiding the isSuperAdmin() grandfather
    // clause a home-org Admin role would trigger) and the customer (home org, Admin) — the
    // same combination AssignmentWriterTest's writerOperatorStaff() fixture uses — but has
    // scoped the console down to just the customer organization.
    $testUser = User::factory()->for($this->customerA)->create(['role' => UserRole::Admin]);
    $testUser->organizations()->attach($this->operator->id, ['role' => UserRole::Maintainer->value]);

    $this->registryA->packages()->attach($this->shared->id);
    $this->registryB->packages()->attach($this->shared->id);

    $this->actingAs($testUser)
        ->withSession(['admin.scope_org_id' => $this->customerA->id])
        ->get(route('admin.packages.show', $this->shared))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_manage_assignments', true)
            // Sorted Kunde A, Kunde B (see the grouping test above).
            ->where('assignments.0.group_name', 'Registry A')
            ->where('assignments.0.can_edit', true)
            ->where('assignments.1.group_name', 'Registry B')
            ->where('assignments.1.can_edit', false)
            ->etc());
});

it('marks every row editable for a super-admin spanning every organization', function () {
    $this->registryA->packages()->attach($this->shared->id);
    $this->registryB->packages()->attach($this->shared->id);

    $this->actingAs(superAdmin())->get(route('admin.packages.show', $this->shared))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('assignments.0.can_edit', true)
            ->where('assignments.1.can_edit', true)
            ->etc());
});

it('marks an own packages row editable for its owning organizations admin', function () {
    $this->registryA->packages()->attach($this->own->id);

    $this->actingAs($this->customerAdminA)->get(route('admin.packages.show', $this->own))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('assignments.0.can_edit', true)->etc());
});

it('lists the major version lines that actually exist, for the bounds editor dropdown', function () {
    PackageVersion::factory()->for($this->own)->create(['version' => '1.2.3']);
    PackageVersion::factory()->for($this->own)->create(['version' => '2.0.0']);
    PackageVersion::factory()->for($this->own)->create(['version' => '2.5.0']);

    $this->actingAs($this->customerAdminA)->get(route('admin.packages.show', $this->own))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('major_lines', ['1', '2'])->etc());
});

it('offers only the callers own registries not yet carrying an own package, for the freigeben picker', function () {
    $secondRegistry = Group::factory()->for($this->customerA)->create(['name' => 'Registry A2']);
    $this->registryA->packages()->attach($this->own->id);

    $this->actingAs($this->customerAdminA)->get(route('admin.packages.show', $this->own))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('assignable_groups', 1)
            ->where('assignable_groups.0.id', $secondRegistry->id)
            ->etc());
});

it('offers every administered registry, of any customer, for a shared package', function () {
    $this->actingAs(superAdmin())->get(route('admin.packages.show', $this->shared))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('assignable_groups', 2)->etc());
});

it('offers no registries to pick from for a caller who may not manage assignments', function () {
    $this->registryA->packages()->attach($this->shared->id);

    $this->actingAs($this->customerAdminA)->get(route('admin.packages.show', $this->shared))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('assignable_groups', [])->etc());
});

// -----------------------------------------------------------------------------------------
// store() — a brand new assignment.
// -----------------------------------------------------------------------------------------

it('creates a new assignment with bounds through the store route', function () {
    $this->actingAs($this->customerAdminA)
        ->post(route('admin.packages.assignments.store', $this->own), [
            'group_id' => $this->registryA->id,
            'available_until' => null,
            'version_min' => '1.0.0',
            'version_max' => '2.0.0',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $row = assignmentRowOf($this->registryA, $this->own);
    expect($row)->not->toBeNull()
        ->and($row->version_min)->toBe('1.0.0')
        ->and($row->version_max)->toBe('2.0.0');
});

it('refuses assigning a package the caller does not own and that is not shared, creating no row', function () {
    $foreign = Package::factory()->create(['type' => 'composer', 'name' => 'fremd/paket']);

    $this->actingAs($this->customerAdminA)
        ->post(route('admin.packages.assignments.store', $foreign), [
            'group_id' => $this->registryA->id,
            'available_until' => null,
        ])
        ->assertForbidden();

    expect(assignmentRowOf($this->registryA, $foreign))->toBeNull();
});

it('refuses a customer admin assigning a shared package they do not administer the owner of, creating no row', function () {
    $this->actingAs($this->customerAdminA)
        ->post(route('admin.packages.assignments.store', $this->shared), [
            'group_id' => $this->registryA->id,
            'available_until' => null,
        ])
        ->assertForbidden();

    expect(assignmentRowOf($this->registryA, $this->shared))->toBeNull();
});

// -----------------------------------------------------------------------------------------
// update() — bounds/period on an existing assignment.
// -----------------------------------------------------------------------------------------

it('updates an existing assignments bounds through the update route, preserving the untouched side', function () {
    $this->registryA->packages()->attach($this->own->id, ['version_min' => '1.0.0', 'version_max' => '2.0.0']);

    $this->actingAs($this->customerAdminA)
        ->put(route('admin.packages.assignments.update', [$this->own, $this->registryA]), [
            'available_until' => null,
            'version_min' => '1.5.0',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $row = assignmentRowOf($this->registryA, $this->own);
    expect($row->version_min)->toBe('1.5.0')
        ->and($row->version_max)->toBe('2.0.0');
});

it('refuses updating an assignment that does not exist', function () {
    $this->actingAs($this->customerAdminA)
        ->put(route('admin.packages.assignments.update', [$this->own, $this->registryA]), [
            'available_until' => null,
        ])
        ->assertNotFound();
});

it('refuses a customer admin updating a shared assignment they do not administer the owner of, leaving it unchanged', function () {
    $this->registryA->packages()->attach($this->shared->id, ['version_min' => '1.0.0']);

    $this->actingAs($this->customerAdminA)
        ->put(route('admin.packages.assignments.update', [$this->shared, $this->registryA]), [
            'available_until' => null,
            'version_min' => '9.0.0',
        ])
        ->assertForbidden();

    expect(assignmentRowOf($this->registryA, $this->shared)->version_min)->toBe('1.0.0');
});

it('refuses updating a foreign, non-shared assignment, leaving it unchanged', function () {
    $this->registryA->packages()->attach($this->own->id, ['version_min' => '1.0.0']);

    $this->actingAs($this->customerAdminB)
        ->put(route('admin.packages.assignments.update', [$this->own, $this->registryA]), [
            'available_until' => null,
            'version_min' => '9.0.0',
        ])
        ->assertForbidden();

    expect(assignmentRowOf($this->registryA, $this->own)->version_min)->toBe('1.0.0');
});

// -----------------------------------------------------------------------------------------
// destroy() — ends an assignment outright.
// -----------------------------------------------------------------------------------------

it('removes an assignment through the destroy route', function () {
    $this->registryA->packages()->attach($this->own->id);

    $this->actingAs($this->customerAdminA)
        ->delete(route('admin.packages.assignments.destroy', [$this->own, $this->registryA]))
        ->assertRedirect();

    expect(assignmentRowOf($this->registryA, $this->own))->toBeNull();
});

it('refuses destroying an assignment that does not exist', function () {
    $this->actingAs($this->customerAdminA)
        ->delete(route('admin.packages.assignments.destroy', [$this->own, $this->registryA]))
        ->assertNotFound();
});

it('refuses a customer admin ending a shared assignment they do not administer the owner of, leaving it in place', function () {
    $this->registryA->packages()->attach($this->shared->id);

    $this->actingAs($this->customerAdminA)
        ->delete(route('admin.packages.assignments.destroy', [$this->shared, $this->registryA]))
        ->assertForbidden();

    expect(assignmentRowOf($this->registryA, $this->shared))->not->toBeNull();
});

// -----------------------------------------------------------------------------------------
// The 403/404 oracle: a caller outside the target registry's scope must never learn, from
// the status code alone, whether a (package, group) assignment exists. GroupController's
// own updateAssignment()/detachPackage() ask assertAdministersGroupInScope() BEFORE any
// existence check for exactly this reason; these two routes did it the other way round.
// -----------------------------------------------------------------------------------------

it('gives a caller outside the target registrys scope 403 (never 404) on update, whether or not the assignment exists', function () {
    // customerAdminB does not administer customerA's organization at all, so they may not
    // touch registryA — a question that must be answered before anything about the row.
    $this->actingAs($this->customerAdminB)
        ->put(route('admin.packages.assignments.update', [$this->own, $this->registryA]), [
            'available_until' => null,
        ])
        ->assertForbidden();

    $this->registryA->packages()->attach($this->own->id);

    $this->actingAs($this->customerAdminB)
        ->put(route('admin.packages.assignments.update', [$this->own, $this->registryA]), [
            'available_until' => null,
        ])
        ->assertForbidden();
});

it('gives a caller outside the target registrys scope 403 (never 404) on destroy, whether or not the assignment exists', function () {
    $this->actingAs($this->customerAdminB)
        ->delete(route('admin.packages.assignments.destroy', [$this->own, $this->registryA]))
        ->assertForbidden();

    $this->registryA->packages()->attach($this->own->id);

    $this->actingAs($this->customerAdminB)
        ->delete(route('admin.packages.assignments.destroy', [$this->own, $this->registryA]))
        ->assertForbidden();

    expect(assignmentRowOf($this->registryA, $this->own))->not->toBeNull();
});
