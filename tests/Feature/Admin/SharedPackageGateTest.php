<?php

use App\Enums\SharedPackageRole;
use App\Models\Organization;
use App\Models\SystemSetting;

it('defaults to letting only a super-admin share', function () {
    expect(SystemSetting::current()->shared_package_role)->toBe(SharedPackageRole::SuperAdmin);

    expect(superAdmin()->can('share-packages'))->toBeTrue()
        ->and(operatorOrgAdmin()->can('share-packages'))->toBeFalse();
});

it('lets an operator-organization admin share once the setting says so', function () {
    SystemSetting::current()->update(['shared_package_role' => SharedPackageRole::OperatorAdmin]);

    expect(operatorOrgAdmin()->can('share-packages'))->toBeTrue()
        ->and(superAdmin()->can('share-packages'))->toBeTrue();
});

it('never lets a plain organization admin share, whatever the setting', function () {
    $org = Organization::factory()->create(['is_operator' => false]);
    $admin = adminOf($org);

    foreach (SharedPackageRole::cases() as $role) {
        SystemSetting::current()->update(['shared_package_role' => $role]);
        expect($admin->can('share-packages'))->toBeFalse();
    }
});
