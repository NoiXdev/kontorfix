<?php

use App\Enums\TokenAbility;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('issues an org token without an owner by default', function () {
    $org = Organization::factory()->create();

    [$token, $plain] = RegistryToken::issue($org, 'CI', null);

    expect($token->user_id)->toBeNull();
    expect($token->organization_id)->toBe($org->id);
    expect($plain)->toStartWith('kfx_');
});

it('issues a token owned by a user when an owner is passed', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    [$token] = RegistryToken::issue($org, 'Persönlich', null, TokenAbility::Read, null, $user);

    expect($token->user_id)->toBe($user->id);
    expect($token->user->is($user))->toBeTrue();
});
