<?php

use App\Enums\PackageSourceMode;
use App\Enums\UserRole;
use App\Jobs\SyncPackage;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function sourceAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('creates a publish-based npm package without a repository', function () {
    Queue::fake();
    $admin = sourceAdmin();

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'npm', 'name' => '@acme/pub', 'source_mode' => 'publish',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $pkg = Package::where('name', '@acme/pub')->firstOrFail();
    expect($pkg->source_mode)->toBe(PackageSourceMode::Publish)
        ->and($pkg->isPublishSourced())->toBeTrue();
    Queue::assertNotPushed(SyncPackage::class);
});

it('rejects git-mirror mode for npm outright, even with a repository url', function () {
    // Fake the queue: if this refusal ever regresses, the fallthrough create would
    // otherwise dispatch a real SyncPackage against github.com.
    Queue::fake();
    $this->actingAs(sourceAdmin())->post('/admin/packages', [
        'type' => 'npm', 'name' => '@acme/mirror', 'source_mode' => 'git',
        'repository_url' => 'https://github.com/acme/mirror.git',
    ])->assertSessionHasErrors('source_mode');

    expect(Package::where('name', '@acme/mirror')->exists())->toBeFalse();
    Queue::assertNotPushed(SyncPackage::class);
});

it('creates a git-mirror python package and dispatches a sync', function () {
    Queue::fake();
    $admin = sourceAdmin();

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'python', 'name' => 'acme-mirror', 'source_mode' => 'git',
        'repository_url' => 'https://github.com/acme/mirror.git',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $pkg = Package::where('name', 'acme-mirror')->firstOrFail();
    expect($pkg->source_mode)->toBe(PackageSourceMode::Git)
        ->and($pkg->isGitSourced())->toBeTrue();
    Queue::assertPushed(SyncPackage::class);
});

it('requires a repository url for a git-mirror python package', function () {
    $this->actingAs(sourceAdmin())->post('/admin/packages', [
        'type' => 'python', 'name' => 'mirror-lib', 'source_mode' => 'git',
    ])->assertSessionHasErrors('repository_url');
});

it('rejects an explicit non-git source mode for composer', function () {
    // Composer's only allowed mode is Git (PackageSourceMode::allowedFor). An explicit,
    // disallowed submission is now a validation error rather than a silent override —
    // silently coercing bad input hid the same class of mistake this task removes for npm.
    // Fake the queue: if this refusal ever regresses, the fallthrough create would
    // otherwise dispatch a real SyncPackage against github.com.
    Queue::fake();
    $admin = sourceAdmin();

    $this->actingAs($admin)->post('/admin/packages', [
        'type' => 'composer', 'name' => 'acme/lib', 'source_mode' => 'publish',
        'repository_url' => 'https://github.com/acme/lib.git',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertSessionHasErrors('source_mode');

    expect(Package::where('name', 'acme/lib')->exists())->toBeFalse();
    Queue::assertNotPushed(SyncPackage::class);
});
