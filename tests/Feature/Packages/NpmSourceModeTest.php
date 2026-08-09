<?php

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{0: User, 1: string}
 */
function npmModeFixture(): array
{
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    return [$admin, homeRegistryId($admin)];
}

/**
 * @return array{0: User, 1: string}
 */
function pythonModeFixture(): array
{
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    return [$admin, homeRegistryId($admin)];
}

/**
 * @return array{0: User, 1: string}
 */
function composerModeFixture(): array
{
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    return [$admin, homeRegistryId($admin)];
}

/**
 * @return array{0: User, 1: string, 2: string}
 */
function npmApiFixture(): array
{
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $org->id, 'role' => 'admin']);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    return [$admin, homeRegistryId($admin), $plain];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function npmPayload(string $groupId, array $overrides = []): array
{
    return array_merge([
        'type' => 'npm',
        'name' => 'acme-demo',
        'group_ids' => [$groupId],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pythonPayload(string $groupId, array $overrides = []): array
{
    return array_merge([
        'type' => 'python',
        'name' => 'acme-pydemo',
        'group_ids' => [$groupId],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function composerPayload(string $groupId, array $overrides = []): array
{
    return array_merge([
        'type' => 'composer',
        'name' => 'acme/php-demo',
        'group_ids' => [$groupId],
    ], $overrides);
}

it('refuses an npm package in git-mirror mode on the web path', function () {
    // A real SyncPackage dispatch would attempt an actual git clone; faking the queue
    // keeps this a pure validation test regardless of whether the refusal holds.
    Queue::fake();
    [$admin, $groupId] = npmModeFixture();

    $this->actingAs($admin)
        ->from('/admin/packages')
        ->post('/admin/packages', npmPayload($groupId, [
            'source_mode' => 'git',
            'repository_url' => 'https://github.test/acme/demo.git',
        ]))
        ->assertSessionHasErrors('source_mode');

    expect(Package::where('name', 'acme-demo')->exists())->toBeFalse();
});

it('still accepts an npm package in publish mode', function () {
    Queue::fake();
    [$admin, $groupId] = npmModeFixture();

    $this->actingAs($admin)
        ->post('/admin/packages', npmPayload($groupId, ['source_mode' => 'publish']))
        ->assertRedirect();

    expect(Package::where('name', 'acme-demo')->exists())->toBeTrue();
});

it('still accepts a python package in git-mirror mode', function () {
    Queue::fake();
    [$admin, $groupId] = pythonModeFixture();

    $this->actingAs($admin)
        ->post('/admin/packages', pythonPayload($groupId, [
            'source_mode' => 'git',
            'repository_url' => 'https://github.test/acme/pydemo.git',
        ]))
        ->assertRedirect()->assertSessionHasNoErrors();
});

it('still resolves composer to git without the client sending a mode', function () {
    Queue::fake();
    [$admin, $groupId] = composerModeFixture();

    $this->actingAs($admin)
        ->post('/admin/packages', composerPayload($groupId, [
            'repository_url' => 'https://github.test/acme/php-demo.git',
        ]))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(Package::where('name', 'acme/php-demo')->first()->source_mode->value)->toBe('git');
});

it('refuses an npm package in git-mirror mode on the api path', function () {
    Queue::fake();
    [$user, $groupId, $key] = npmApiFixture();

    $this->withHeader('Authorization', "Bearer {$key}")
        ->postJson('/api/v1/packages', npmPayload($groupId, [
            'source_mode' => 'git',
            'repository_url' => 'https://github.test/acme/demo.git',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('source_mode');
});
