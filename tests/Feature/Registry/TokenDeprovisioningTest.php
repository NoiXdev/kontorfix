<?php

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;

/**
 * A personal registry token must not outlive its owner's access to the organization it
 * was issued for. All of these go through RegistryToken::findByPlainText(), the single
 * place where a plaintext credential is resolved into a token (AuthenticateRegistry).
 */
it('stops resolving a personal token once the owner loses membership of the token org', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);
    $user->organizations()->attach($org->id, ['role' => UserRole::Member->value]);

    [, $plain] = RegistryToken::issue($org, 'personal', null, TokenAbility::Read, null, $user);
    expect(RegistryToken::findByPlainText($plain))->not->toBeNull();

    $user->organizations()->detach($org->id);

    expect(RegistryToken::findByPlainText($plain))->toBeNull();
});

it('stops resolving a personal token once the owner moves to a different home organization', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->for($org)->create(['role' => UserRole::Member]);

    [, $plain] = RegistryToken::issue($org, 'personal', null, TokenAbility::Read, null, $user);
    expect(RegistryToken::findByPlainText($plain))->not->toBeNull();

    $user->update(['organization_id' => Organization::factory()->create()->id]);

    expect(RegistryToken::findByPlainText($plain))->toBeNull();
});

it('stops resolving a personal token once the owner account is deleted', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->for($org)->create(['role' => UserRole::Member]);

    [$token, $plain] = RegistryToken::issue($org, 'personal', null, TokenAbility::Read, null, $user);

    $user->delete();

    expect(RegistryToken::findByPlainText($plain))->toBeNull()
        ->and(RegistryToken::whereKey($token->id)->exists())->toBeFalse();
});

it('stops resolving a personal publish token once the owner is demoted below maintainer', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->for($org)->create(['role' => UserRole::Maintainer]);

    [, $publish] = RegistryToken::issue($org, 'ci', null, TokenAbility::Publish, null, $user);
    [, $read] = RegistryToken::issue($org, 'pull', null, TokenAbility::Read, null, $user);

    $user->update(['role' => UserRole::Member]);

    // The publish token may never grant more than its owner could obtain right now
    // (RegistryTokenPolicy::create), while the read token is self-service for any member.
    expect(RegistryToken::findByPlainText($publish))->toBeNull()
        ->and(RegistryToken::findByPlainText($read))->not->toBeNull();
});

it('keeps resolving an ownerless organization token', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->for($org)->create(['role' => UserRole::Admin]);

    // Issued by the admin console / API without an owner: an organization credential.
    [, $plain] = RegistryToken::issue($org, 'shared-ci', null, TokenAbility::Publish);

    $user->delete();

    expect(RegistryToken::findByPlainText($plain))->not->toBeNull();
});

/**
 * Housekeeping half: resolution already refuses these tokens, but the rows must also go,
 * so the admin token list does not accumulate credentials that look live but are not.
 */
it('revokes the personal tokens when an admin detaches the membership', function () {
    $operatorAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
    $org = Organization::factory()->create();
    $user = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);
    $user->organizations()->attach($org->id, ['role' => UserRole::Member->value]);

    [$token] = RegistryToken::issue($org, 'personal', null, TokenAbility::Read, null, $user);

    $this->actingAs($operatorAdmin)
        ->delete("/admin/organizations/{$org->id}/members/{$user->id}")
        ->assertRedirect();

    expect(RegistryToken::whereKey($token->id)->exists())->toBeFalse();
});

it('keeps the tokens of the organizations a detached user still belongs to', function () {
    $operatorAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
    $home = Organization::factory()->create();
    $extra = Organization::factory()->create();
    $user = User::factory()->for($home)->create(['role' => UserRole::Member]);
    $user->organizations()->attach($extra->id, ['role' => UserRole::Member->value]);

    [$homeToken] = RegistryToken::issue($home, 'home', null, TokenAbility::Read, null, $user);
    [$extraToken] = RegistryToken::issue($extra, 'extra', null, TokenAbility::Read, null, $user);

    $this->actingAs($operatorAdmin)->delete("/admin/users/{$user->id}/organizations/{$extra->id}");

    expect(RegistryToken::whereKey($homeToken->id)->exists())->toBeTrue()
        ->and(RegistryToken::whereKey($extraToken->id)->exists())->toBeFalse();
});

it('lets a user set an expiry on a self-issued token', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->for($org)->create(['role' => UserRole::Member]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/settings/tokens', [
            'name' => 'laptop',
            'expires_at' => now()->addDays(30)->toDateString(),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(RegistryToken::where('user_id', $user->id)->sole()->expires_at)->not->toBeNull();
});

it('leaves a self-issued token open-ended when no expiry is given', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->for($org)->create(['role' => UserRole::Member]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/settings/tokens', ['name' => 'laptop'])
        ->assertSessionHasNoErrors();

    expect(RegistryToken::where('user_id', $user->id)->sole()->expires_at)->toBeNull();
});
