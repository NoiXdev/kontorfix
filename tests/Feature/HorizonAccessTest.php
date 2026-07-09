<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows only operator admins to view horizon', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $operatorAdmin = User::factory()->for($operator)->create(['role' => UserRole::Admin]);
    $operatorMaintainer = User::factory()->for($operator)->create(['role' => UserRole::Maintainer]);
    $customerAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);

    expect(Gate::forUser($operatorAdmin)->allows('viewHorizon'))->toBeTrue();
    expect(Gate::forUser($operatorMaintainer)->allows('viewHorizon'))->toBeFalse();
    expect(Gate::forUser($customerAdmin)->allows('viewHorizon'))->toBeFalse();
});
