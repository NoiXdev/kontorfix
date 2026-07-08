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

it('generates time-ordered uuid v7 keys', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();

    expect($first->id[14])->toBe('7')   // Versions-Nibble
        ->and($second->id[14])->toBe('7')
        ->and(strcmp($first->id, $second->id))->toBeLessThan(0);
});

it('defaults organizations to non-operator with a boolean cast', function () {
    expect(Organization::factory()->create()->is_operator)->toBeFalse();
});
