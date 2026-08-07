<?php

// tests/Feature/Settings/PublishTokenEscalationTest.php
// A publish-capable registry token is an organization credential: it writes into the
// tenant's registries. A plain `member` must never be able to mint one for themselves
// through the self-service surfaces (settings + portal), which are reachable with the
// `auth` middleware alone. See routes/api.php: "registry tokens are organization
// credentials, so they are admin/maintainer-only".

use App\Enums\ApiKeyPermission;
use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The self-service credential pages sit behind `password.confirm`; this file is about the
// role check behind that gate, so every request starts from a confirmed-password session.
beforeEach(fn () => $this->withSession(['auth.password_confirmed_at' => time()]));

it('forbids a member from minting an org-wide publish token via settings', function () {
    $org = Organization::factory()->create();
    $member = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Member]);

    $this->actingAs($member)
        ->post('/settings/tokens', ['name' => 'evil', 'ability' => 'publish'])
        ->assertForbidden();

    expect(RegistryToken::count())->toBe(0);
});

it('forbids a member from minting a group-scoped publish token via settings', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $member = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Member]);

    $this->actingAs($member)
        ->post('/settings/tokens', ['name' => 'evil', 'group_id' => $group->id, 'ability' => 'publish'])
        ->assertForbidden();

    expect(RegistryToken::count())->toBe(0);
});

it('forbids a member from minting a publish token via the portal', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $member = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Member]);

    $this->actingAs($member)->from('/portal')
        ->post('/portal/tokens', ['name' => 'evil', 'group_id' => $group->id, 'ability' => 'publish'])
        ->assertForbidden();

    expect(RegistryToken::count())->toBe(0);
});

it('forbids a member who only has an additional membership in the target org', function () {
    // Home org: maintainer (so the account is not blanket-blocked), target org: member.
    $home = Organization::factory()->create();
    $target = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $home->id, 'role' => UserRole::Maintainer]);
    $user->organizations()->attach($target->id, ['role' => UserRole::Member->value]);
    $group = Group::factory()->for($target)->create();

    $this->actingAs($user)
        ->post('/settings/tokens', ['name' => 'evil', 'group_id' => $group->id, 'ability' => 'publish'])
        ->assertForbidden();

    expect(RegistryToken::count())->toBe(0);
});

it('still lets a member mint a read token', function () {
    $org = Organization::factory()->create();
    $member = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Member]);

    $this->actingAs($member)
        ->post('/settings/tokens', ['name' => 'pull', 'ability' => 'read'])
        ->assertRedirect()
        ->assertSessionHas('plainTextToken');

    expect(RegistryToken::sole()->ability)->toBe(TokenAbility::Read);
});

it('still lets an org maintainer mint a publish token', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $maintainer = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Maintainer]);

    $this->actingAs($maintainer)
        ->post('/settings/tokens', ['name' => 'ci', 'group_id' => $group->id, 'ability' => 'publish'])
        ->assertRedirect()
        ->assertSessionHas('plainTextToken');

    expect(RegistryToken::sole()->ability)->toBe(TokenAbility::Publish);
});

it('still lets an org maintainer mint a publish token via the portal', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $maintainer = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Maintainer]);

    $this->actingAs($maintainer)->from('/portal')
        ->post('/portal/tokens', ['name' => 'ci', 'group_id' => $group->id, 'ability' => 'publish'])
        ->assertRedirect('/portal');

    expect(RegistryToken::sole()->ability)->toBe(TokenAbility::Publish);
});

it('keeps the api registry-token endpoint closed to members', function () {
    // Regression guard for the API path: `operator` middleware + resolveWriteOrg already
    // block a member, so the same escalation must not be reachable through /api/v1.
    $org = Organization::factory()->create();
    $member = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Member]);
    [, $plain] = ApiKey::issue($member, 'w', ApiKeyPermission::Write);

    $this->withToken($plain)->postJson('/api/v1/registry-tokens', [
        'name' => 'evil',
        'organization_id' => $org->id,
        'ability' => 'publish',
    ])->assertForbidden();

    expect(RegistryToken::count())->toBe(0);
});
