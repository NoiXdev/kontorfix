<?php

use App\Enums\AccountType;
use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->op = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create(['organization_id' => $this->op->id, 'role' => 'admin']);
    [, $this->plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);
});

it('creates a robot account and issues a key for it', function () {
    $res = $this->withToken($this->plain)->postJson('/api/v1/users', [
        'name' => 'CI Bot',
        'email' => 'ci@acme.test',
        'organization_id' => $this->op->id,
        'role' => 'maintainer',
        'account_type' => 'robot',
    ])->assertCreated()->assertJsonPath('data.account_type', 'robot');

    $robotId = $res->json('data.id');
    expect(User::find($robotId)->account_type)->toBe(AccountType::Robot);

    $this->withToken($this->plain)->postJson("/api/v1/users/{$robotId}/api-keys", [
        'name' => 'bot-key', 'permission' => 'write',
    ])->assertCreated()->assertJsonPath('data.plain_text', fn ($v) => str_starts_with($v, 'kfxapi_'));
});

it('filters robots and enforces the operator role invariant', function () {
    $this->withToken($this->plain)->getJson('/api/v1/users?account_type=robot')->assertOk();

    // maintainer in a non-operator org is not allowed (invariant).
    $customer = Organization::factory()->create(['is_operator' => false]);
    $this->withToken($this->plain)->postJson('/api/v1/users', [
        'name' => 'X', 'email' => 'x@acme.test', 'organization_id' => $customer->id, 'role' => 'maintainer',
    ])->assertStatus(422);
});

it('refuses to delete yourself or the last operator admin via api', function () {
    // The sole operator admin must not be able to delete themselves.
    $this->withToken($this->plain)->deleteJson("/api/v1/users/{$this->admin->id}")
        ->assertStatus(422);
    expect(User::find($this->admin->id))->not->toBeNull();

    // A second, deletable user works.
    $victim = User::factory()->create(['organization_id' => $this->op->id, 'role' => 'member']);
    $this->withToken($this->plain)->deleteJson("/api/v1/users/{$victim->id}")->assertNoContent();
});

it('refuses to issue an api key for a human account', function () {
    // $this->op / $this->admin / $this->plain from the beforeEach (operator admin write key).
    $human = User::factory()->create(['organization_id' => $this->op->id, 'role' => 'member']);
    $this->withToken($this->plain)->postJson("/api/v1/users/{$human->id}/api-keys", ['name' => 'x', 'permission' => 'write'])
        ->assertStatus(422);
    expect(ApiKey::where('user_id', $human->id)->count())->toBe(0);
});
