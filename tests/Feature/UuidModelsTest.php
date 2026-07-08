<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

it('creates users and organizations with uuid v7 keys', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->for($org)->create(['role' => UserRole::Admin]);

    expect(Str::isUuid($org->id))->toBeTrue()
        ->and(Str::isUuid($user->id))->toBeTrue()
        ->and($user->organization->is($org))->toBeTrue()
        ->and($user->role)->toBe(UserRole::Admin);
});
