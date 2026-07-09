<?php

use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use App\Services\RegistryAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a personal global token in settings and it authenticates against a group of the same org', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $group = Group::factory()->create(['organization_id' => $org->id, 'public' => false]);

    $this->actingAs($user)->post('/settings/tokens', ['name' => 'ci', 'ability' => 'read'])
        ->assertSessionHas('plainTextToken');

    $plain = session('plainTextToken');
    $token = RegistryToken::findByPlainText($plain);

    expect($token)->not->toBeNull();
    expect($token->user_id)->toBe($user->id);
    expect($token->group_id)->toBeNull();
    expect($token->ability)->toBe(TokenAbility::Read);
    expect($token->organization_id)->toBe($group->organization_id);

    $access = app(RegistryAccessService::class);
    expect($access->canAccessGroup($token, $group))->toBeTrue();
});
