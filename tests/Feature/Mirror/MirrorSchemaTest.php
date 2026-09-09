<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Models\MirrorSource;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has the mirror_sources table and the new packages columns', function () {
    expect(Schema::hasTable('mirror_sources'))->toBeTrue()
        ->and(Schema::hasColumn('mirror_sources', 'id'))->toBeTrue()
        ->and(Schema::hasColumn('mirror_sources', 'organization_id'))->toBeTrue()
        ->and(Schema::hasColumn('mirror_sources', 'name'))->toBeTrue()
        ->and(Schema::hasColumn('mirror_sources', 'type'))->toBeTrue()
        ->and(Schema::hasColumn('mirror_sources', 'url'))->toBeTrue()
        ->and(Schema::hasColumn('mirror_sources', 'auth_token'))->toBeTrue()
        ->and(Schema::hasColumn('mirror_sources', 'last_used_at'))->toBeTrue()
        ->and(Schema::hasColumn('packages', 'mirror_source_id'))->toBeTrue()
        ->and(Schema::hasColumn('packages', 'mirror_name'))->toBeTrue();
});

it('creates a mirror source with sensible defaults', function () {
    $source = MirrorSource::factory()->create();

    expect($source->type)->toBe(PackageType::Composer)
        ->and($source->url)->toBe('https://repo.example.test')
        ->and($source->auth_token)->toBeNull()
        ->and($source->last_used_at)->toBeNull();
});

it('scopes mirror sources to their owning organization', function () {
    $owner = Organization::factory()->create();
    $stranger = Organization::factory()->create();

    $own = MirrorSource::factory()->for($owner, 'organization')->create();
    MirrorSource::factory()->for($stranger, 'organization')->create();

    expect(MirrorSource::query()->ownedBy($owner->id)->pluck('id')->all())->toBe([$own->id]);
});

it('survives its mirror source being deleted, losing only the link', function () {
    $source = MirrorSource::factory()->create();
    $package = Package::factory()->create([
        'organization_id' => $source->organization_id,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => 'vendor/package',
    ]);

    $source->delete();

    // nullOnDelete: the package row is not touched by the FK, only the link column is
    // cleared — a mirror source going away must not take the packages built from it with it.
    expect(Package::query()->whereKey($package->id)->exists())->toBeTrue()
        ->and($package->fresh()->mirror_source_id)->toBeNull()
        ->and($package->fresh()->mirror_name)->toBe('vendor/package');
});

it('encrypts the auth token at rest and hides it from serialisation', function () {
    $source = MirrorSource::factory()->create(['auth_token' => 'plaintext-secret']);

    // The raw value in the DB is encrypted, the model returns plaintext.
    $raw = DB::table('mirror_sources')->where('id', $source->id)->value('auth_token');
    expect($raw)->not->toBe('plaintext-secret')
        ->and($source->fresh()->auth_token)->toBe('plaintext-secret');

    // Never serialised — must not reach the frontend or API.
    expect($source->toArray())->not->toHaveKey('auth_token');
});

it('matches the allowedFor() matrix for every package type, order included', function () {
    expect(PackageSourceMode::allowedFor(PackageType::Composer))
        ->toBe([PackageSourceMode::Git, PackageSourceMode::Mirror])
        ->and(PackageSourceMode::allowedFor(PackageType::Npm))
        ->toBe([PackageSourceMode::Publish, PackageSourceMode::Mirror])
        ->and(PackageSourceMode::allowedFor(PackageType::Python))
        ->toBe([PackageSourceMode::Publish, PackageSourceMode::Git, PackageSourceMode::Mirror])
        ->and(PackageSourceMode::allowedFor(PackageType::Docker))
        ->toBe([PackageSourceMode::Publish]);
});

it('labels the mirror mode', function () {
    expect(PackageSourceMode::Mirror->label())->toBe('Mirror (fremde Registry)');
});

it('answers isMirrorSourced() from the stored source mode alone', function () {
    $mirrored = Package::factory()->create(['type' => PackageType::Composer, 'source_mode' => PackageSourceMode::Mirror]);
    $gitSourced = Package::factory()->create(['type' => PackageType::Composer, 'source_mode' => PackageSourceMode::Git]);

    expect($mirrored->isMirrorSourced())->toBeTrue()
        ->and($gitSourced->isMirrorSourced())->toBeFalse();
});

// isPublishSourced() used to be `! isGitSourced()`, which was correct back when Publish and
// Git were the only two modes but silently answered true for Mirror as well once a third
// mode existed — with real consequences: NpmController::publish and PypiController::upload's
// guard, and Admin/Api PackageController::resync()'s guard, all key off this method to
// decide whether a package may be written to directly. A mirror-sourced package is exactly
// as "not writable directly" as a git-sourced one, so this must read false for it, not true.
it('answers isPublishSourced() false for a mirror-sourced package', function () {
    $mirrored = Package::factory()->create(['type' => PackageType::Composer, 'source_mode' => PackageSourceMode::Mirror]);
    $published = Package::factory()->create(['type' => PackageType::Npm, 'source_mode' => PackageSourceMode::Publish]);
    $gitSourced = Package::factory()->create(['type' => PackageType::Composer, 'source_mode' => PackageSourceMode::Git]);

    expect($mirrored->isPublishSourced())->toBeFalse()
        ->and($published->isPublishSourced())->toBeTrue()
        ->and($gitSourced->isPublishSourced())->toBeFalse();
});

/** Same shape as GroupSlugUniquenessMigrationTest's runScopeGroupSlugMigration(). */
function runAddMirrorSourcesMigration(): object
{
    return require database_path('migrations/2026_09_09_100000_add_mirror_sources.php');
}

it('rolls back the mirror source table and both package columns, cleanly', function () {
    // RefreshDatabase has already run up() for this test; populate a row down() has to
    // survive (the base package) and a row it has to get rid of (the mirror source), so a
    // wrong drop order would surface here rather than only on a bare schema.
    $source = MirrorSource::factory()->create();
    $package = Package::factory()->create([
        'organization_id' => $source->organization_id,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => 'vendor/package',
    ]);

    runAddMirrorSourcesMigration()->down();

    expect(Schema::hasTable('mirror_sources'))->toBeFalse()
        ->and(Schema::hasColumn('packages', 'mirror_source_id'))->toBeFalse()
        ->and(Schema::hasColumn('packages', 'mirror_name'))->toBeFalse();

    // The package row itself survives — only the columns/table this migration added are
    // gone.
    expect(Package::query()->whereKey($package->id)->exists())->toBeTrue();

    // up() re-applies cleanly afterward: a failed deploy that rolls back and retries is not
    // left with a schema neither migration recognises.
    runAddMirrorSourcesMigration()->up();

    expect(Schema::hasTable('mirror_sources'))->toBeTrue()
        ->and(Schema::hasColumn('packages', 'mirror_source_id'))->toBeTrue()
        ->and(Schema::hasColumn('packages', 'mirror_name'))->toBeTrue();
});
