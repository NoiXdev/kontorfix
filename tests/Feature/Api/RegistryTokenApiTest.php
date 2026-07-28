<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin']);
    [, $this->plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);
});

it('issues a registry token and returns the plaintext once', function () {
    $this->withToken($this->plain)->postJson('/api/v1/registry-tokens', [
        'name' => 'ci-pull',
        'organization_id' => $this->org->id,
        'ability' => 'read',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'ci-pull')
        ->assertJsonPath('data.plain_text', fn ($v) => str_starts_with($v, 'kfx_'));
});

it('lists and revokes registry tokens', function () {
    [$token] = RegistryToken::issue($this->org, 'old', null);
    $this->withToken($this->plain)->getJson('/api/v1/registry-tokens')->assertOk();
    $this->withToken($this->plain)->deleteJson("/api/v1/registry-tokens/{$token->id}")->assertNoContent();
    expect(RegistryToken::find($token->id))->toBeNull();
});
