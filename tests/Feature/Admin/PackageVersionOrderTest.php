<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;

it('sends the admin detail page its versions newest first', function () {
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);
    $package = Package::factory()->create();
    $package->groups()->attach(homeRegistryId($admin));

    foreach (['1.9.0', '1.10.0', '1.2.0'] as $v) {
        PackageVersion::factory()->for($package)->create(['version' => $v, 'version_pretty' => $v]);
    }

    $this->actingAs($admin)
        ->get(route('admin.packages.show', $package))
        ->assertInertia(fn ($page) => $page->where(
            'versions.0.version', '1.10.0'
        )->where('versions.1.version', '1.9.0'));
});
