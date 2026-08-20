<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
});

it('stores a cadence the enum allows', function () {
    $org = Organization::factory()->create();

    // 'is_operator' and 'slug' are submitted alongside the cadence to prove the update
    // route cannot be used to escalate a customer org into the operator org (or hijack
    // its slug) even if a caller adds those fields to the request body — the FormRequest's
    // validated() only carries 'name' and 'notification_cadence' through to update().
    $this->actingAs($this->admin)
        ->put(route('admin.organizations.update', $org), [
            'name' => $org->name,
            'notification_cadence' => 'daily',
            'is_operator' => true,
            'slug' => 'hijacked-slug',
        ])
        ->assertSessionHasNoErrors();

    $fresh = $org->fresh();
    expect($fresh->notification_cadence)->toBe('daily')
        ->and($fresh->is_operator)->toBeFalse()
        ->and($fresh->slug)->toBe($org->slug);
});

it('rejects a cadence outside the three allowed values', function () {
    $org = Organization::factory()->create();

    $this->actingAs($this->admin)
        ->put(route('admin.organizations.update', $org), ['name' => $org->name, 'notification_cadence' => 'monthly'])
        ->assertSessionHasErrors('notification_cadence');
});
