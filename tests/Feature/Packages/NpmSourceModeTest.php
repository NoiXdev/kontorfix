<?php

use App\Enums\ApiKeyPermission;
use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Jobs\SyncPackage;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * An operator admin plus a registry to create into. Identical across npm/Python/Composer —
 * only the payload differs per type — so one fixture serves all three web-path tests.
 *
 * @return array{0: User, 1: string}
 */
function sourceModeFixture(): array
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

it('advertises one source mode for npm and two for python', function () {
    [$admin] = sourceModeFixture();

    $this->actingAs($admin)
        ->get('/admin/packages')
        ->assertInertia(fn ($page) => $page
            ->count('sourceModes.npm', 1)
            ->where('sourceModes.npm.0.value', 'publish')
            ->count('sourceModes.python', 2)
            ->count('sourceModes.composer', 1)
            // Order matters, not just membership: the dialog's onTypeChange() seeds
            // form.source_mode from sourceModes[type][0].value, so the first entry is
            // what gets submitted when the user never touches the selector.
            ->where('sourceModes.composer.0.value', 'git')
            ->where('sourceModes.python.0.value', 'publish')
        );
});

it('refuses an npm package in git-mirror mode on the web path', function () {
    // A real SyncPackage dispatch would attempt an actual git clone; faking the queue
    // keeps this a pure validation test regardless of whether the refusal holds.
    Queue::fake();
    [$admin, $groupId] = sourceModeFixture();

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
    [$admin, $groupId] = sourceModeFixture();

    $this->actingAs($admin)
        ->post('/admin/packages', npmPayload($groupId, ['source_mode' => 'publish']))
        ->assertRedirect();

    expect(Package::where('name', 'acme-demo')->exists())->toBeTrue();
});

it('still accepts a python package in git-mirror mode', function () {
    Queue::fake();
    [$admin, $groupId] = sourceModeFixture();

    $this->actingAs($admin)
        ->post('/admin/packages', pythonPayload($groupId, [
            'source_mode' => 'git',
            'repository_url' => 'https://github.test/acme/pydemo.git',
        ]))
        ->assertRedirect()->assertSessionHasNoErrors();
});

// This is also the shape the admin dialog (resources/js/pages/admin/packages/Index.vue)
// now actually sends for Composer: its `useForm` defaults `source_mode` to 'publish' and
// the selector stays hidden for Composer (only one allowed mode), so the submit-time
// `.transform()` strips the field entirely rather than send a value the type disallows.
// Before that fix this request carried an explicit `source_mode: 'publish'` and 422'd on
// a field the user could not see — see "rejects an explicit non-git source mode for
// composer" in PackageSourceModeCreateTest.php for the server-side proof that an explicit,
// disallowed value is (and must stay) rejected.
it('still resolves composer to git without the client sending a mode', function () {
    Queue::fake();
    [$admin, $groupId] = sourceModeFixture();

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

// A positive control: without this, npmApiFixture() is only ever exercised by a request
// expected to fail, so a bug that made the API path refuse *everything* for npm (not just
// git) would not show up as a regression anywhere in this file.
it('still accepts an npm package in publish mode on the api path', function () {
    Queue::fake();
    [$user, $groupId, $key] = npmApiFixture();

    $this->withHeader('Authorization', "Bearer {$key}")
        ->postJson('/api/v1/packages', npmPayload($groupId, ['source_mode' => 'publish']))
        ->assertCreated()
        ->assertJsonPath('data.name', 'acme-demo');

    expect(Package::where('name', 'acme-demo')->first()->source_mode->value)->toBe('publish');
});

// Api\V1\PackageController::store() did not apply effectiveSourceMode() before creating —
// unlike Admin\PackageController::store(). A composer package created with no source_mode
// at all silently fell through to the `source_mode` column's DB default ('publish'),
// masked only by Package::isGitSourced()'s `type === Composer ||` fallback. This proves
// the stored column itself is correct, not just the computed accessor.
it('resolves composer to git on the api path too, without the client sending a mode', function () {
    Queue::fake();
    [$user, $groupId, $key] = npmApiFixture();

    $this->withHeader('Authorization', "Bearer {$key}")
        ->postJson('/api/v1/packages', composerPayload($groupId, [
            'repository_url' => 'https://github.test/acme/php-demo.git',
        ]))
        ->assertCreated();

    expect(Package::where('name', 'acme/php-demo')->first()->source_mode->value)->toBe('git');
});

it('refuses to sync an npm package left in git-mirror mode', function () {
    // Created directly, bypassing validation, because validation is exactly what this
    // backstop exists for the absence of — a row that predates the rule.
    $package = Package::factory()->create([
        'type' => 'npm',
        'source_mode' => 'git',
        'repository_url' => 'https://github.test/acme/demo.git',
    ]);

    (new SyncPackage($package))->handle();

    expect($package->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($package->fresh()->sync_error)->toContain('npm');
});

// The dispatch sites this job's guard cannot see into (packages:resync, incoming webhooks,
// and — until the fix below — the API create path) do not all check isGitSourced() before
// queuing a sync. A publish-mode package can carry a repository_url purely for reference
// (npm publish sends the tarball, not the tree), so the job must refuse that combination
// too, not just a disallowed mode — otherwise it would clone a repository for a package
// that was never meant to be git-synced at all.
it('refuses to sync a publish-mode package that carries a stray repository_url', function () {
    $package = Package::factory()->create([
        'type' => 'npm',
        'source_mode' => 'publish',
        'repository_url' => 'https://github.test/acme/demo.git',
    ]);

    (new SyncPackage($package))->handle();

    expect($package->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($package->fresh()->sync_error)->toContain('nicht git-basiert');
});

// The remedy in that message must not recommend a mode the type cannot actually use.
// npm's allowedFor() is [Publish] only, so "switch to Git-Mirror" would send an operator
// straight into a validation error on both create paths — it must be withheld. Python's
// allowedFor() includes Git, so the same clause is a real, actionable remedy there and
// must be offered. This is the control-removal check for that conditional: making the
// clause unconditional again would turn the npm assertion red.
it('only recommends switching to git-mirror when the type actually allows it', function () {
    $npmPackage = Package::factory()->create([
        'type' => 'npm',
        'source_mode' => 'publish',
        'repository_url' => 'https://github.test/acme/demo.git',
    ]);
    $pythonPackage = Package::factory()->create([
        'type' => 'python',
        'source_mode' => 'publish',
        'repository_url' => 'https://github.test/acme/lib.git',
    ]);

    (new SyncPackage($npmPackage))->handle();
    (new SyncPackage($pythonPackage))->handle();

    expect($npmPackage->fresh()->sync_error)
        ->not->toContain('Git-Mirror stellen')
        ->and($pythonPackage->fresh()->sync_error)
        ->toContain('Git-Mirror stellen');
});

// Api\V1\PackageController::store() dispatched whenever repository_url was set, unlike
// Admin\PackageController::store() which also requires isGitSourced(). A publish-mode
// package with a reference-only repository_url (legitimate today; also the shape Task 5's
// migration leaves existing npm git-mirror rows in) would queue a sync doomed to fail,
// leaving a package that was never meant to sync at all sitting in sync_status=failed.
it('does not dispatch a sync for a publish-mode npm package with a reference repository_url', function () {
    Queue::fake();
    [$user, $groupId, $key] = npmApiFixture();

    $this->withHeader('Authorization', "Bearer {$key}")
        ->postJson('/api/v1/packages', npmPayload($groupId, [
            'source_mode' => 'publish',
            'repository_url' => 'https://github.test/acme/demo.git',
        ]))
        ->assertCreated();

    Queue::assertNotPushed(SyncPackage::class);
});
