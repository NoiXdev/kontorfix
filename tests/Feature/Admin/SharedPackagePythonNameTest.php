<?php

/*
 * Spec §5's refusal, for the ecosystem where "the same name" is not the same string.
 *
 * PyPI resolves a PEP 503-normalised project name, so `Shared_Lib`, `shared.lib` and
 * `shared-lib` are ONE project to pip. `SharedAssignment` compared the stored strings
 * verbatim, so it saw three different names and accepted a collision the spec refuses
 * unconditionally — and having accepted it, the registry then silently picked one side:
 *
 *   - Shared arriving over own: `pythonPackagesOfGroup()` orders own-organization rows
 *     first, so `GET /simple/shared-lib/` answered with the CUSTOMER'S project. The
 *     operator's deliberate assignment did nothing at all, and said so nowhere.
 *   - Own arriving over shared: the new own row jumps ahead of the shared one, and the
 *     shared project stops resolving in that registry from the moment it is created.
 *
 * Both directions are asserted, because the guard is stated over the post-state and a
 * refusal that only caught the arriving-shared case would satisfy half of §5 — that is
 * exactly the direction blindness the sibling file was written against.
 *
 * The verbatim ecosystems are asserted too, in the same file and on the same shapes.
 * Composer resolves the stored string, so `Acme/Tools` and `acme/tools` are two packages
 * there and must stay assignable together: a fix that normalised every type would refuse a
 * pair of packages that never collide, which is the same class of wrong answer in the other
 * direction.
 */

use App\Enums\ApiKeyPermission;
use App\Enums\PackageType;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

/**
 * A shared Python project owned by the operator organization.
 *
 * Only an operator-organization package can be marked shared (Admin\PackageController::shared),
 * so `shared === true` already implies operator ownership; the factory states both. A
 * separate function from `sharedPackage()` in SharedPackageAssignmentTest rather than a
 * parameter on it: a top-level function only exists once its declaring file has been
 * required, so calling that one here would break this file when run on its own.
 */
function sharedPythonPackage(string $name): Package
{
    return Package::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['type' => PackageType::Python, 'name' => $name, 'shared' => true]);
}

function pythonWriteKeyFor(User $user): string
{
    [, $plain] = ApiKey::issue($user, 'shared-python-name', ApiKeyPermission::Write);

    return $plain;
}

// The message names the key the conflict exists under, which for Python is the normalised
// form and may be neither of the two spellings stored.
const PYTHON_ALREADY_SERVED_MESSAGE = 'Diese Registry führt bereits ein eigenes Paket mit demselben Namen: '
    .'python shared-lib. Ein geteiltes Paket darf ein eigenes nicht verdecken.';

const PYTHON_SUBMITTED_TOGETHER_MESSAGE = 'Diese Auswahl enthält ein eigenes und ein geteiltes Paket mit demselben '
    .'Namen: python shared-lib. Ein geteiltes Paket darf ein eigenes nicht verdecken.';

const PYTHON_NAME_HELD_MESSAGE = 'Die Registry Kundenregistry führt dieses Paket bereits als geteiltes Paket. '
    .'Entfernen Sie es dort zuerst, oder wählen Sie einen anderen Namen.';

// ---------------------------------------------------------------------------------------
// Shared arriving over an own project of the same normalised name.
// ---------------------------------------------------------------------------------------

it('refuses a shared python project whose normalised name the registry already serves', function () {
    $customer = Group::factory()->create(['name' => 'Kundenregistry']);
    $own = Package::factory()->inOrgOf($customer)->create(['type' => PackageType::Python, 'name' => 'Shared_Lib']);
    $customer->packages()->attach($own);

    $shared = sharedPythonPackage('shared-lib');

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$shared->id]])
        ->assertSessionHasErrors(['package_ids' => PYTHON_ALREADY_SERVED_MESSAGE]);

    expect($customer->fresh()->packages()->whereKey($shared->id)->exists())->toBeFalse();
});

it('refuses a shared python project whose normalised name the registry already serves through the api', function () {
    $customer = Group::factory()->create(['name' => 'Kundenregistry']);
    $own = Package::factory()->inOrgOf($customer)->create(['type' => PackageType::Python, 'name' => 'Shared_Lib']);
    $customer->packages()->attach($own);

    $shared = sharedPythonPackage('shared.lib');

    $this->withToken(pythonWriteKeyFor(superAdmin()))
        ->putJson("/api/v1/groups/{$customer->id}/packages", ['package_ids' => [$own->id, $shared->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['package_ids' => PYTHON_SUBMITTED_TOGETHER_MESSAGE]);

    expect($customer->fresh()->packages()->whereKey($shared->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------
// The mirror: an own project created under a name a shared assignment already serves.
// ---------------------------------------------------------------------------------------

it('refuses creating an own python project whose normalised name a shared assignment serves', function () {
    $customer = Group::factory()->create(['name' => 'Kundenregistry']);
    $customer->packages()->attach(sharedPythonPackage('shared-lib'));

    $this->actingAs(adminOf($customer->organization))
        ->post(route('admin.packages.store'), [
            'type' => 'python',
            'name' => 'Shared_Lib',
            'group_ids' => [$customer->id],
        ])
        ->assertSessionHasErrors(['name' => PYTHON_NAME_HELD_MESSAGE]);

    // Refused before the insert, so there is no orphan package either.
    expect(Package::where('name', 'Shared_Lib')->exists())->toBeFalse();
});

it('refuses creating an own python project whose normalised name a shared assignment serves through the api', function () {
    $customer = Group::factory()->create(['name' => 'Kundenregistry']);
    $customer->packages()->attach(sharedPythonPackage('Shared.Lib'));

    $this->withToken(pythonWriteKeyFor(superAdmin()))
        ->postJson('/api/v1/packages', [
            'type' => 'python',
            'name' => 'shared_lib',
            'group_ids' => [$customer->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => PYTHON_NAME_HELD_MESSAGE]);

    expect(Package::where('name', 'shared_lib')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------
// The other side of the rule: normalising is a PYTHON rule, not a global one.
// ---------------------------------------------------------------------------------------

it('still lets a composer package be assigned beside a shared one of a differently spelled name', function () {
    // Composer resolves the stored string, so these two are genuinely different packages and
    // neither shadows the other. Without this case, "normalise everything" would pass.
    $customer = Group::factory()->create(['name' => 'Kundenregistry']);
    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'Acme/Tools']);
    $customer->packages()->attach($own);

    $shared = Package::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['type' => 'composer', 'name' => 'acme/tools', 'shared' => true]);

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$shared->id]])
        ->assertSessionHasNoErrors();

    expect($customer->fresh()->packages()->whereKey($shared->id)->exists())->toBeTrue();
});

it('still lets a python project be created under a name no shared assignment normalises to', function () {
    // The reversal of the creation case: a guard that refused every python creation once any
    // shared python assignment existed would pass the two refusals above.
    $customer = Group::factory()->create(['name' => 'Kundenregistry']);
    $customer->packages()->attach(sharedPythonPackage('shared-lib'));

    $this->actingAs(adminOf($customer->organization))
        ->post(route('admin.packages.store'), [
            'type' => 'python',
            'name' => 'shared-lib2',
            'group_ids' => [$customer->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Package::where('name', 'shared-lib2')->exists())->toBeTrue();
});

it('lets an own python project be created under the name of a LAPSED shared assignment', function () {
    // assignedPackages(), not packages(): a lapsed assignment serves nothing, so it holds no
    // name. Normalising the comparison must not quietly change which rows it is asked over.
    $customer = Group::factory()->create(['name' => 'Kundenregistry']);
    $customer->packages()->attach(sharedPythonPackage('shared-lib'), ['available_until' => now()->subDay()]);

    $this->actingAs(adminOf($customer->organization))
        ->post(route('admin.packages.store'), [
            'type' => 'python',
            'name' => 'Shared_Lib',
            'group_ids' => [$customer->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Package::where('name', 'Shared_Lib')->exists())->toBeTrue();
});
