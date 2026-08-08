<?php

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;

// Minting a registry token or an API key hands out a long-lived bearer credential that
// outlives the session it was created from. Like passkey registration, it must re-prove
// the password, so a stolen session cannot be upgraded into durable standalone access.

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
});

it('requires password confirmation to list registry tokens', function () {
    $this->actingAs($this->user)->get('/settings/tokens')
        ->assertRedirect(route('password.confirm'));
});

it('requires password confirmation to mint a registry token', function () {
    $this->actingAs($this->user)->post('/settings/tokens', ['name' => 'stolen'])
        ->assertRedirect(route('password.confirm'));

    expect(RegistryToken::where('user_id', $this->user->id)->count())->toBe(0);
});

it('requires password confirmation to list api keys', function () {
    $this->actingAs($this->user)->get('/settings/api-keys')
        ->assertRedirect(route('password.confirm'));
});

it('requires password confirmation to mint an api key', function () {
    $this->actingAs($this->user)->post('/settings/api-keys', ['name' => 'stolen', 'permission' => 'read'])
        ->assertRedirect(route('password.confirm'));

    expect(ApiKey::where('user_id', $this->user->id)->count())->toBe(0);
});

// The gate only means something if it covers every route that mints the same class of
// credential. `settings/*` was gated first; the portal and the two admin surfaces issue
// the identical `RegistryToken`/`ApiKey` and were left open, so a session thief simply
// used those instead.

it('requires password confirmation to mint a registry token through the portal', function () {
    $group = Group::factory()->for($this->organization)->create();

    $this->actingAs($this->user)->from('/portal')
        ->post('/portal/tokens', ['name' => 'stolen', 'group_id' => $group->id])
        ->assertRedirect(route('password.confirm'));

    expect(RegistryToken::count())->toBe(0);
});

it('requires password confirmation to mint a registry token in the admin console', function () {
    $admin = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role' => UserRole::Admin,
    ]);

    $this->actingAs($admin)->from('/admin/tokens')
        ->post('/admin/tokens', ['name' => 'stolen', 'organization_id' => $this->organization->id])
        ->assertRedirect(route('password.confirm'));

    expect(RegistryToken::count())->toBe(0);
});

it('requires password confirmation to issue a robot api key', function () {
    $super = User::factory()->create([
        'organization_id' => $this->organization->id,
        'role' => UserRole::Admin,
        'is_super_admin' => true,
    ]);
    $robot = User::factory()->create([
        'organization_id' => $this->organization->id,
        'email' => null,
        'account_type' => AccountType::Robot,
        'password' => null,
    ]);

    $this->actingAs($super)->from('/admin/robots')
        ->post("/admin/robots/{$robot->id}/keys", ['name' => 'stolen', 'permission' => 'read'])
        ->assertRedirect(route('password.confirm'));

    expect(ApiKey::count())->toBe(0);
});

it('lets a user with a freshly confirmed password mint a registry token', function () {
    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/settings/tokens', ['name' => 'ci'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(RegistryToken::where('user_id', $this->user->id)->count())->toBe(1);
});
