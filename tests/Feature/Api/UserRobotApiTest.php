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

    // maintainer in einer Nicht-Operator-Org ist unzulässig (Invariante).
    $customer = Organization::factory()->create(['is_operator' => false]);
    $this->withToken($this->plain)->postJson('/api/v1/users', [
        'name' => 'X', 'email' => 'x@acme.test', 'organization_id' => $customer->id, 'role' => 'maintainer',
    ])->assertStatus(422);
});
