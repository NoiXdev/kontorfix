<?php

/*
 * The instance setting behind the `share-packages` gate (spec §3), and the one property
 * that keeps it from being decorative: only a super-admin may change it.
 *
 * The delegation chain is one-directional. A super-admin may widen the setting to include
 * maintainers of the operator organization; a maintainer of the operator organization —
 * the very population the permissive value authorizes — must not be able to widen it
 * themselves. `admin/system` sits behind `['auth', 'super']` for exactly that, so these
 * tests address the route rather than the gate.
 */

use App\Enums\SharedPackageRole;
use App\Models\Organization;
use App\Models\SystemSetting;

it('lets a super-admin change who may share packages', function () {
    $this->actingAs(superAdmin())
        ->put(route('admin.system.update'), [
            'registration_enabled' => false,
            'shared_package_role' => SharedPackageRole::OperatorMaintainer->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(SystemSetting::current()->shared_package_role)->toBe(SharedPackageRole::OperatorMaintainer);
});

it('lets a super-admin narrow the setting again', function () {
    SystemSetting::current()->update(['shared_package_role' => SharedPackageRole::OperatorMaintainer]);

    $this->actingAs(superAdmin())
        ->put(route('admin.system.update'), [
            'registration_enabled' => false,
            'shared_package_role' => SharedPackageRole::SuperAdmin->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(SystemSetting::current()->shared_package_role)->toBe(SharedPackageRole::SuperAdmin);
});

it('refuses a maintainer of the operator organization', function () {
    // The population the permissive value authorizes. If they could set it themselves, the
    // capability would be self-granted and the setting would decide nothing.
    $this->actingAs(operatorMaintainer())
        ->put(route('admin.system.update'), [
            'registration_enabled' => false,
            'shared_package_role' => SharedPackageRole::OperatorMaintainer->value,
        ])
        ->assertForbidden();

    expect(SystemSetting::current()->shared_package_role)->toBe(SharedPackageRole::SuperAdmin);
});

it('refuses an admin of a customer organization', function () {
    $this->actingAs(adminOf(Organization::factory()->create(['is_operator' => false])))
        ->put(route('admin.system.update'), [
            'registration_enabled' => false,
            'shared_package_role' => SharedPackageRole::OperatorMaintainer->value,
        ])
        ->assertForbidden();

    expect(SystemSetting::current()->shared_package_role)->toBe(SharedPackageRole::SuperAdmin);
});

it('rejects a value that is not one of the two roles', function () {
    $this->actingAs(superAdmin())
        ->put(route('admin.system.update'), [
            'registration_enabled' => false,
            'shared_package_role' => 'operator_admin',
        ])
        ->assertSessionHasErrors('shared_package_role');

    expect(SystemSetting::current()->shared_package_role)->toBe(SharedPackageRole::SuperAdmin);
});

it('offers the current value and both options to the settings page', function () {
    SystemSetting::current()->update(['shared_package_role' => SharedPackageRole::OperatorMaintainer]);

    $this->actingAs(superAdmin())->get(route('admin.system.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('settings.shared_package_role', SharedPackageRole::OperatorMaintainer->value)
            ->where('sharedPackageRoles', [
                ['value' => 'super_admin', 'label' => SharedPackageRole::SuperAdmin->label()],
                ['value' => 'operator_maintainer', 'label' => SharedPackageRole::OperatorMaintainer->label()],
            ])
            ->etc());
});

/*
 * The labels are the thing an operator reads before deciding, and both of them can mislead
 * in the same way: an Admin whose home organization is the operator organization is already
 * a super-admin through User::isSuperAdmin()'s grandfather clause, so they may share at
 * EITHER setting and never needed the permissive one. A label reading "only super-admins"
 * with nothing else said invites the reading that operator-org admins are gated, and
 * "additionally admins…" would be worse still — it would name a population that was never
 * excluded. What the permissive value actually adds is maintainers, and only those.
 */
it('says in the strict label that operator-organization admins are already included', function () {
    expect(SharedPackageRole::SuperAdmin->label())
        ->toContain('Super-Admins')
        ->toContain('Betreiber-Organisation');
});

it('says in the permissive label that it adds maintainers', function () {
    expect(SharedPackageRole::OperatorMaintainer->label())
        ->toContain('Maintainer')
        // …and does not name admins at all: they were never excluded, so listing them as
        // something this value grants would state the opposite of what it does.
        ->not->toContain('Admin');
});
