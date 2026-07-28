<?php

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults new users to human and casts the account type', function () {
    $user = User::factory()->create();

    expect($user->account_type)->toBe(AccountType::Human);
    expect($user->isRobot())->toBeFalse();
});

it('creates robot accounts without a password', function () {
    $robot = User::factory()->robot()->create();

    expect($robot->account_type)->toBe(AccountType::Robot);
    expect($robot->isRobot())->toBeTrue();
    expect($robot->password)->toBeNull();
});
