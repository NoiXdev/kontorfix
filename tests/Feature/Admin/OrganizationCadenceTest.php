<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
});

it('stores a cadence the enum allows, and still refuses to escalate is_operator', function () {
    $org = Organization::factory()->create();

    // 'is_operator' is submitted alongside the cadence to prove the update route cannot be
    // used to escalate a customer org into the operator org even if a caller adds that
    // field to the request body — the FormRequest's validated() only carries 'name',
    // 'slug' and 'notification_cadence' through to update(). 'slug' is deliberately
    // included too, but as of App\Http\Requests\Admin\UpdateOrganizationRequest (Task 5:
    // editable organization slugs) it is a legitimate field on this same route rather than
    // an extraneous one, so it is expected to actually apply.
    $this->actingAs($this->admin)
        ->put(route('admin.organizations.update', $org), [
            'name' => $org->name,
            'notification_cadence' => 'daily',
            'is_operator' => true,
            'slug' => 'new-cadence-slug',
        ])
        ->assertSessionHasNoErrors();

    $fresh = $org->fresh();
    expect($fresh->notification_cadence)->toBe('daily')
        ->and($fresh->is_operator)->toBeFalse()
        ->and($fresh->slug)->toBe('new-cadence-slug');
});

it('rejects a cadence outside the three allowed values', function () {
    $org = Organization::factory()->create();

    $this->actingAs($this->admin)
        ->put(route('admin.organizations.update', $org), [
            'name' => $org->name, 'notification_cadence' => 'monthly', 'slug' => $org->slug,
        ])
        ->assertSessionHasErrors('notification_cadence');
});
