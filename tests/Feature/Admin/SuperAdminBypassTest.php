<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('lets a super-admin pass any gate via Gate::before', function () {
    $super = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member, 'is_super_admin' => true]);

    // viewApiDocs plus an arbitrary undefined ability — both short-circuit to true.
    expect(Gate::forUser($super)->allows('viewApiDocs'))->toBeTrue()
        ->and(Gate::forUser($super)->allows('some-undefined-ability'))->toBeTrue();
});

it('grandfathers an operator-org admin into the super-admin bypass', function () {
    $operatorAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);

    expect($operatorAdmin->isSuperAdmin())->toBeTrue()
        ->and(Gate::forUser($operatorAdmin)->allows('viewApiDocs'))->toBeTrue();
});

it('does not let a customer-org admin view the api docs', function () {
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);

    expect($custAdmin->isSuperAdmin())->toBeFalse()
        ->and(Gate::forUser($custAdmin)->allows('viewApiDocs'))->toBeFalse();
});

it('reflects a toggled super-admin flag in access decisions', function () {
    $user = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Maintainer]);
    expect(Gate::forUser($user)->allows('some-undefined-ability'))->toBeFalse();

    $user->update(['is_super_admin' => true]);
    expect(Gate::forUser($user->fresh())->allows('some-undefined-ability'))->toBeTrue();
});
