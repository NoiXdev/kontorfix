<?php

use App\Enums\ApiKeyPermission;
use App\Enums\PackageType;
use App\Jobs\SyncMirrorPackage;
use App\Jobs\SyncPackage;
use App\Models\ApiKey;
use App\Models\MirrorSource;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function operatorWriteToken(): string
{
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $org->id, 'role' => 'admin']);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    return $plain;
}

it('lists and shows packages, including their versions', function () {
    $plain = operatorWriteToken();
    $package = Package::factory()->create(['name' => 'acme/widget']);
    PackageVersion::factory()->for($package)->create(['version_pretty' => 'v1.2.3']);

    $this->withToken($plain)->getJson('/api/v1/packages')
        ->assertOk()->assertJsonPath('data.0.name', 'acme/widget');

    $this->withToken($plain)->getJson("/api/v1/packages/{$package->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'acme/widget')
        ->assertJsonPath('data.versions.0.version', 'v1.2.3');
});

it('creates a package and dispatches a sync', function () {
    Queue::fake();
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $org->id, 'role' => 'admin']);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    $this->withToken($plain)->postJson('/api/v1/packages', [
        'type' => 'composer',
        'name' => 'acme/new',
        'repository_url' => 'https://github.com/acme/new.git',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertCreated()->assertJsonPath('data.name', 'acme/new');

    Queue::assertPushed(SyncPackage::class);
});

it('triggers a resync', function () {
    Queue::fake();
    $plain = operatorWriteToken();
    $package = Package::factory()->create(['repository_url' => 'https://github.com/acme/w.git']);

    $this->withToken($plain)->postJson("/api/v1/packages/{$package->id}/resync")->assertOk();
    Queue::assertPushed(SyncPackage::class);
});

it('refuses a resync request against a publish-based package with a reference-only repository url', function () {
    Queue::fake();
    $plain = operatorWriteToken();
    $package = Package::factory()->create([
        'type' => 'npm',
        'source_mode' => 'publish',
        'repository_url' => 'https://github.com/acme/reference-only.git',
    ]);

    // 409, not a silent 200: dispatch IS the point of this endpoint, so declining it must
    // not be reported as success. Status code asserted explicitly (not assertOk()'s
    // opposite) so this is the control-removal check for the abort_if() guard — dropping
    // it would make this assertion fail with 200 instead of 409.
    $this->withToken($plain)->postJson("/api/v1/packages/{$package->id}/resync")
        ->assertStatus(409);

    Queue::assertNothingPushed();
});

it('dispatches SyncMirrorPackage for a mirror-sourced package resync request', function () {
    Queue::fake();
    $plain = operatorWriteToken();
    $source = MirrorSource::factory()->create(['type' => PackageType::Composer]);
    $package = Package::factory()->create([
        'organization_id' => $source->organization_id,
        'type' => PackageType::Composer,
        'source_mode' => 'mirror',
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/demo',
        'repository_url' => null,
    ]);

    $this->withToken($plain)->postJson("/api/v1/packages/{$package->id}/resync")->assertOk();

    Queue::assertPushed(SyncMirrorPackage::class, fn (SyncMirrorPackage $job): bool => $job->package->is($package));
    Queue::assertNotPushed(SyncPackage::class);
});

it('creates a mirror-sourced package via the api and dispatches a mirror sync', function () {
    Queue::fake();
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $org->id, 'role' => 'admin']);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);
    $source = MirrorSource::factory()->create(['organization_id' => $org->id, 'type' => PackageType::Composer]);

    $this->withToken($plain)->postJson('/api/v1/packages', [
        'type' => 'composer',
        'name' => 'acme/mirrored',
        'source_mode' => 'mirror',
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/upstream-name',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertCreated()->assertJsonPath('data.name', 'acme/mirrored');

    $pkg = Package::where('name', 'acme/mirrored')->firstOrFail();
    Queue::assertPushed(SyncMirrorPackage::class, fn (SyncMirrorPackage $job) => $job->package->is($pkg));
});

it('refuses to create an api package pointed at a mirror source of another organization, and persists no row', function () {
    Queue::fake();
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $org->id, 'role' => 'admin']);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);
    $foreignSource = MirrorSource::factory()->create(['type' => PackageType::Composer]);

    $this->withToken($plain)->postJson('/api/v1/packages', [
        'type' => 'composer',
        'name' => 'acme/foreign',
        'source_mode' => 'mirror',
        'mirror_source_id' => $foreignSource->id,
        'mirror_name' => 'acme/foreign',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertForbidden();

    expect(Package::where('name', 'acme/foreign')->exists())->toBeFalse();
    Queue::assertNotPushed(SyncMirrorPackage::class);
});

it('refuses to create an api package pointed at a mirror source of a mismatched type, and persists no row', function () {
    Queue::fake();
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $org->id, 'role' => 'admin']);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);
    $npmSource = MirrorSource::factory()->create(['organization_id' => $org->id, 'type' => PackageType::Npm]);

    $this->withToken($plain)->postJson('/api/v1/packages', [
        'type' => 'composer',
        'name' => 'acme/mismatch',
        'source_mode' => 'mirror',
        'mirror_source_id' => $npmSource->id,
        'mirror_name' => 'acme/mismatch',
        'group_ids' => [homeRegistryId($admin)],
    ])->assertStatus(422);

    expect(Package::where('name', 'acme/mismatch')->exists())->toBeFalse();
    Queue::assertNotPushed(SyncMirrorPackage::class);
});

it('lets a member read (scoped) but not write packages', function () {
    $org = Organization::factory()->create(['is_operator' => false]);
    $member = User::factory()->create(['organization_id' => $org->id, 'role' => 'member']);
    [, $plain] = ApiKey::issue($member, 'w', ApiKeyPermission::Write);

    // Read is allowed but scoped to the member's own organizations (none here → empty).
    $this->withToken($plain)->getJson('/api/v1/packages')->assertOk()->assertJsonCount(0, 'data');

    // Writes require an admin/maintainer role — a member is denied.
    $this->withToken($plain)->postJson('/api/v1/packages', [
        'type' => 'composer', 'name' => 'acme/x', 'repository_url' => 'https://github.com/acme/x.git',
    ])->assertForbidden();
});
