<?php

use App\Models\Organization;
use App\Models\Package;

it('refuses to delete an organization that still owns packages', function () {
    $org = Organization::factory()->create();
    Package::factory()->for($org)->create();

    $this->actingAs(superAdmin())
        ->delete(route('admin.organizations.destroy', $org))
        ->assertSessionHasErrors('organization');

    expect(Organization::whereKey($org->id)->exists())->toBeTrue();
});
