<?php

// `POST /api/v1/me/api-keys` cannot carry `password.confirm` — the gate reads a session key
// and /api/v1 is stateless. A key therefore mints its own successors, which is how a leaked
// key survives its own revocation. The route stays (it is a robot's only way to rotate its
// own credential), but a successor may be neither wider nor longer-lived than its parent.

use App\Enums\AccountType;
use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;

it('refuses a successor that outlives the key that minted it', function () {
    $me = User::factory()->create();
    [, $plain] = ApiKey::issue($me, 'parent', ApiKeyPermission::Write, now()->addDays(7));

    $this->withToken($plain)->postJson('/api/v1/me/api-keys', [
        'name' => 'immortal',
        'permission' => 'write',
        'expires_at' => now()->addDays(3650)->toIso8601String(),
    ])->assertStatus(422)->assertJsonValidationErrors('expires_at');

    expect($me->apiKeys()->count())->toBe(1);
});

it('refuses a never-expiring successor of an expiring key', function () {
    $me = User::factory()->create();
    [, $plain] = ApiKey::issue($me, 'parent', ApiKeyPermission::Write, now()->addDays(7));

    // The default shape of the attack: omit expires_at entirely and the successor used to
    // outlive every rotation of the credential it was minted from.
    $this->withToken($plain)->postJson('/api/v1/me/api-keys', [
        'name' => 'immortal',
        'permission' => 'write',
    ])->assertStatus(422)->assertJsonValidationErrors('expires_at');

    expect($me->apiKeys()->count())->toBe(1);
});

it('allows a successor that dies with or before its parent', function () {
    $me = User::factory()->create();
    [, $plain] = ApiKey::issue($me, 'parent', ApiKeyPermission::Write, now()->addDays(7));

    $this->withToken($plain)->postJson('/api/v1/me/api-keys', [
        'name' => 'rotation',
        'permission' => 'write',
        'expires_at' => now()->addDays(6)->toIso8601String(),
    ])->assertCreated();

    expect($me->apiKeys()->count())->toBe(2);
});

it('honours the instance-wide ceiling on the web form too', function () {
    config(['kontorfix.api_key_max_ttl_days' => 30]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/settings/api-keys', [
            'name' => 'forever',
            'permission' => 'read',
        ])->assertSessionHasErrors('expires_at');

    expect($user->apiKeys()->count())->toBe(0);
});

it('leaves the perpetual web-minted key alone when no ceiling is configured', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/settings/api-keys', [
            'name' => 'ci',
            'permission' => 'read',
        ])->assertSessionHasNoErrors();

    expect($user->apiKeys()->sole()->expires_at)->toBeNull();
});

it('refuses a never-expiring successor of a never-expiring key', function () {
    // The shape the first version of this rule returned early on, and the common one: a
    // perpetual parent minted perpetual successors, so revoking the leaked key ended
    // nothing. `api_key_successor_max_ttl_days` bounds it without shortening any key that
    // already exists.
    $me = User::factory()->create();
    [, $plain] = ApiKey::issue($me, 'perpetual', ApiKeyPermission::Write);

    $this->withToken($plain)->postJson('/api/v1/me/api-keys', [
        'name' => 'immortal',
        'permission' => 'write',
    ])->assertStatus(422)->assertJsonValidationErrors('expires_at');

    expect($me->apiKeys()->count())->toBe(1);
});

it('refuses a successor of a never-expiring key beyond the successor ceiling', function () {
    $me = User::factory()->create();
    [, $plain] = ApiKey::issue($me, 'perpetual', ApiKeyPermission::Write);

    $this->withToken($plain)->postJson('/api/v1/me/api-keys', [
        'name' => 'too-long',
        'permission' => 'write',
        'expires_at' => now()->addDays(91)->toIso8601String(),
    ])->assertStatus(422)->assertJsonValidationErrors('expires_at');

    expect($me->apiKeys()->count())->toBe(1);
});

it('still lets a never-expiring key rotate itself inside the ceiling', function () {
    // The anchor, and the reason this is not an outage: the route exists so a robot can
    // rotate its own credential, and it still can. It has to keep doing so, which is the
    // whole difference between a chain that ends and one that does not.
    $me = User::factory()->create();
    [, $plain] = ApiKey::issue($me, 'perpetual', ApiKeyPermission::Write);

    $this->withToken($plain)->postJson('/api/v1/me/api-keys', [
        'name' => 'rotation',
        'permission' => 'write',
        'expires_at' => now()->addDays(89)->toIso8601String(),
    ])->assertCreated();

    expect($me->apiKeys()->count())->toBe(2);
});

it('lets an operator opt back into the self-renewing chain', function () {
    config(['kontorfix.api_key_successor_max_ttl_days' => 0]);
    $me = User::factory()->create();
    [, $plain] = ApiKey::issue($me, 'perpetual', ApiKeyPermission::Write);

    $this->withToken($plain)->postJson('/api/v1/me/api-keys', [
        'name' => 'immortal',
        'permission' => 'write',
    ])->assertCreated();

    expect($me->apiKeys()->count())->toBe(2);
});

it('never lets a read key mint any key at all', function () {
    // The fifth audit called `StoreApiKeyRequest`'s read-parent-may-not-mint-write branch a
    // coverage gap. Measured: it cannot be covered through this route, because
    // `AuthenticateApiKey` refuses every non-GET from a read key before the request object
    // is ever built. The branch is defence in depth behind that 403, not the control — and
    // the control is what is pinned here, so removing the 403 has to turn something red.
    $me = User::factory()->create();
    [, $plain] = ApiKey::issue($me, 'reader', ApiKeyPermission::Read, now()->addDays(7));

    foreach (['write', 'read'] as $permission) {
        $this->withToken($plain)->postJson('/api/v1/me/api-keys', [
            'name' => 'escalation',
            'permission' => $permission,
            'expires_at' => now()->addDays(6)->toIso8601String(),
        ])->assertForbidden();
    }

    expect($me->apiKeys()->count())->toBe(1);
});

it('lets a write key mint one on the same route — the anchor for the case above', function () {
    $me = User::factory()->create();
    [, $plain] = ApiKey::issue($me, 'writer', ApiKeyPermission::Write, now()->addDays(7));

    $this->withToken($plain)->postJson('/api/v1/me/api-keys', [
        'name' => 'rotation',
        'permission' => 'read',
        'expires_at' => now()->addDays(6)->toIso8601String(),
    ])->assertCreated();

    expect($me->apiKeys()->count())->toBe(2);
});

it('applies the instance ceiling to a robot key issued from the console', function () {
    // A robot's keys are not enumerable through any route, so a ceiling silently bypassed
    // here is a perpetual credential nobody can find.
    config(['kontorfix.api_key_max_ttl_days' => 30]);
    $org = Organization::factory()->create(['is_operator' => true]);
    $superAdmin = User::factory()->for($org)->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
    $robot = User::factory()->for($org)->create([
        'role' => UserRole::Member, 'account_type' => AccountType::Robot, 'email' => null, 'password' => null,
    ]);

    $this->actingAs($superAdmin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/admin/robots/{$robot->id}/keys", ['name' => 'ci', 'permission' => 'read'])
        ->assertRedirect();

    expect($robot->apiKeys()->sole()->expires_at?->toDateString())->toBe(now()->addDays(30)->toDateString());
});

it('leaves a robot key open-ended when no ceiling is configured', function () {
    $org = Organization::factory()->create(['is_operator' => true]);
    $superAdmin = User::factory()->for($org)->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
    $robot = User::factory()->for($org)->create([
        'role' => UserRole::Member, 'account_type' => AccountType::Robot, 'email' => null, 'password' => null,
    ]);

    $this->actingAs($superAdmin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/admin/robots/{$robot->id}/keys", ['name' => 'ci', 'permission' => 'read'])
        ->assertRedirect();

    expect($robot->apiKeys()->sole()->expires_at)->toBeNull();
});
