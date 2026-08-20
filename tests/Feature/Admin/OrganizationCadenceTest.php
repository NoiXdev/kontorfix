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

    $this->actingAs($this->admin)
        ->put(route('admin.organizations.update', $org), ['name' => $org->name, 'notification_cadence' => 'daily'])
        ->assertSessionHasNoErrors();

    expect($org->fresh()->notification_cadence)->toBe('daily');
});

it('rejects a cadence outside the three allowed values', function () {
    $org = Organization::factory()->create();

    $this->actingAs($this->admin)
        ->put(route('admin.organizations.update', $org), ['name' => $org->name, 'notification_cadence' => 'monthly'])
        ->assertSessionHasErrors('notification_cadence');
});
