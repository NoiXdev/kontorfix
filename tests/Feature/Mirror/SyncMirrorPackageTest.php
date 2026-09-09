<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Enums\SyncStatus;
use App\Events\PackageSynced;
use App\Events\PackageSyncFailed;
use App\Exceptions\MirrorSyncFailed;
use App\Jobs\SyncMirrorPackage;
use App\Jobs\SyncPackage;
use App\Models\MirrorSource;
use App\Models\Organization;
use App\Models\Package;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{name: string, version: string, version_normalized: string, dist: array<string, mixed>, time?: string}
 */
function syncMirrorComposerVersion(string $name, string $tag, string $normalized, string $distUrl): array
{
    return [
        'name' => $name,
        'version' => $tag,
        'version_normalized' => $normalized,
        'description' => 'Description straight from the upstream feed',
        'dist' => ['type' => 'zip', 'url' => $distUrl],
        'time' => '2024-01-01T00:00:00+00:00',
    ];
}

function fakeMirrorFeed(string $baseUrl, string $name): void
{
    Http::fake([
        "{$baseUrl}/p2/{$name}.json" => Http::response([
            'packages' => [$name => MetadataMinifier::minify([
                syncMirrorComposerVersion($name, 'v1.0.0', '1.0.0.0', "{$baseUrl}/dist/a.zip"),
            ])],
        ], 200),
        "{$baseUrl}/dist/a.zip" => Http::response('zip-bytes-v1', 200),
    ]);
}

/**
 * @return array{0: MirrorSource, 1: Package}
 */
function mirrorSyncFixture(): array
{
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    $source = MirrorSource::factory()->create([
        'organization_id' => $org->id,
        'type' => PackageType::Composer,
        'url' => 'https://repo.test',
    ]);
    $package = Package::factory()->create([
        'organization_id' => $org->id,
        'name' => 'acme/demo',
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/demo',
        'repository_url' => null,
        'description' => null,
    ]);

    return [$source, $package];
}

it('syncs a mirror package end to end and stamps the source last_used_at', function () {
    [$source, $package] = mirrorSyncFixture();
    Event::fake([PackageSynced::class]);
    fakeMirrorFeed('https://repo.test', 'acme/demo');

    (new SyncMirrorPackage($package))->handle();

    $package->refresh();
    expect($package->sync_status)->toBe(SyncStatus::Synced)
        ->and($package->sync_error)->toBeNull()
        ->and($package->synced_at)->not->toBeNull()
        ->and($package->description)->toBe('Description straight from the upstream feed');

    Event::assertDispatched(PackageSynced::class, fn (PackageSynced $e) => $e->package->is($package));

    // GitCredential::isUsableBy()'s caller (Package::gitAuth()) stamps last_used_at the
    // same way — forceFill + saveQuietly() — on every successful use of a credential; a
    // MirrorSource is this pipeline's equivalent of a credential, so its last use must be
    // stamped the same way on sync success.
    expect($source->fresh()->last_used_at)->not->toBeNull()
        ->and($source->fresh()->last_used_at->diffInSeconds(now()))->toBeLessThan(5);
});

it('fails a non-mirror package without ever touching the importer', function () {
    Storage::fake('artifacts');
    $package = Package::factory()->create([
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Git,
        'mirror_source_id' => null,
        'repository_url' => 'https://github.test/acme/demo.git',
    ]);
    Http::fake();

    (new SyncMirrorPackage($package))->handle();

    expect($package->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($package->fresh()->sync_error)->toContain('nicht mirror-basiert');
    Http::assertNothingSent();
});

it('fails when the assigned mirror source has been deleted', function () {
    Storage::fake('artifacts');
    $package = Package::factory()->create([
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => null,
        'mirror_name' => 'acme/demo',
        'repository_url' => null,
    ]);
    Http::fake();

    (new SyncMirrorPackage($package))->handle();

    expect($package->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($package->fresh()->sync_error)->toContain('Mirror-Quelle');
    Http::assertNothingSent();
});

// Mutation target: removing this preflight must turn this test red. The fixture's source
// is otherwise fully valid (right type, exists) — only the organization is wrong — so
// nothing except the org-drift check itself can be the reason the importer is never
// invoked.
it('fails when the mirror source belongs to another organization, without invoking the importer', function () {
    Storage::fake('artifacts');
    $packageOrg = Organization::factory()->create();
    $otherOrg = Organization::factory()->create();
    $source = MirrorSource::factory()->create([
        'organization_id' => $otherOrg->id,
        'type' => PackageType::Composer,
        'url' => 'https://repo.test',
    ]);
    $package = Package::factory()->create([
        'organization_id' => $packageOrg->id,
        'name' => 'acme/demo',
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/demo',
        'repository_url' => null,
    ]);
    fakeMirrorFeed('https://repo.test', 'acme/demo');

    (new SyncMirrorPackage($package))->handle();

    expect($package->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($package->fresh()->sync_error)->toContain('andere');
    Http::assertNothingSent();
});

it('fails when the mirror source type does not match the package type', function () {
    Storage::fake('artifacts');
    $org = Organization::factory()->create();
    $source = MirrorSource::factory()->create([
        'organization_id' => $org->id,
        'type' => PackageType::Npm,
        'url' => 'https://repo.test',
    ]);
    $package = Package::factory()->create([
        'organization_id' => $org->id,
        'name' => 'acme/demo',
        'type' => PackageType::Composer,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => 'acme/demo',
        'repository_url' => null,
    ]);
    Http::fake();

    (new SyncMirrorPackage($package))->handle();

    expect($package->fresh()->sync_status)->toBe(SyncStatus::Failed);
    Http::assertNothingSent();
});

it('records a MirrorSyncFailed message in sync_error and rethrows for the queue to retry', function () {
    [, $package] = mirrorSyncFixture();
    Http::fake(['https://repo.test/p2/acme/demo.json' => Http::response('', 404)]);

    expect(fn () => (new SyncMirrorPackage($package))->handle())->toThrow(MirrorSyncFailed::class);

    expect($package->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($package->fresh()->sync_error)->not->toBeEmpty();
});

it('fires PackageSyncFailed once from the failed() hook, carrying the stored sync_error', function () {
    Event::fake([PackageSyncFailed::class]);
    $package = Package::factory()->create(['sync_error' => 'stale error from an earlier attempt']);

    (new SyncMirrorPackage($package))->failed(new RuntimeException('boom'));

    Event::assertDispatched(PackageSyncFailed::class, fn (PackageSyncFailed $e) => $e->error === 'boom');
});

it('prefers the stored sync_error over an opaque MaxAttemptsExceededException message', function () {
    [, $package] = mirrorSyncFixture();
    Http::fake(['https://repo.test/p2/acme/demo.json' => Http::response('', 404)]);
    Event::fake([PackageSyncFailed::class]);

    expect(fn () => (new SyncMirrorPackage($package))->handle())->toThrow(MirrorSyncFailed::class);

    (new SyncMirrorPackage($package))->failed(new MaxAttemptsExceededException('attempted too many times'));

    Event::assertDispatched(PackageSyncFailed::class, fn (PackageSyncFailed $e) => str_contains($e->error, 'Composer-v2'));
});

it('shares its worker timeout with SyncPackage, so the Horizon supervisor covers both', function () {
    expect(SyncMirrorPackage::TIMEOUT)->toBe(SyncPackage::TIMEOUT);
});

it('declares the same retry/backoff shape as SyncPackage', function () {
    $job = new SyncMirrorPackage(Package::factory()->create());

    expect($job->maxExceptions)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900])
        ->and(property_exists($job, 'tries'))->toBeFalse();
});
