<?php

use App\Enums\PackageSourceMode;
use App\Enums\PackageType;
use App\Models\Group;
use App\Models\MirrorSource;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Mirror\MirrorImporter;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Follow-up from the mirror-packages feature's final review: a hard worker kill (timeout
 * SIGALRM) between fetchArtifact()'s writeStream() and move() — or, for npm, between its own
 * staging write and the move past verifyIntegrity() — leaves a `.part`/`.integrity-check` file
 * on the artifacts disk forever. Nothing else ever sweeps a non-OCI artifact path.
 *
 * SyncMirrorPackage's WithoutOverlapping($package->id) guarantees no two syncs of the SAME
 * package ever run concurrently, so ANY staging file already sitting in a package's own
 * artifact directory when import() starts cannot belong to a sync in flight — it is always
 * garbage left by a previous run that never reached its move(). import() therefore sweeps its
 * own package id's staging directories before doing anything else.
 */
function mirrorImporterOrphanPackage(Group $group, MirrorSource $source, string $name, PackageType $type): Package
{
    return Package::factory()->inOrgOf($group)->create([
        'organization_id' => $source->organization_id,
        'name' => $name,
        'type' => $type,
        'source_mode' => PackageSourceMode::Mirror,
        'mirror_source_id' => $source->id,
        'mirror_name' => $name,
        'repository_url' => null,
    ]);
}

it('deletes a stale .part staging file and a stale .integrity-check file for the package, leaving real artifacts untouched', function () {
    Storage::fake('artifacts');
    $disk = Storage::disk('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Composer]);
    $pkg = mirrorImporterOrphanPackage($group, $source, 'acme/demo', PackageType::Composer);

    // A real, already-imported artifact — must survive the sweep untouched.
    $realPath = 'dists/'.$pkg->id.'/'.sha1('1.0.0.0').'.zip';
    $disk->put($realPath, 'real-bytes');

    // Orphaned staging leftovers, exactly the shapes fetchArtifact() and NpmMirrorImport's
    // stagingPath() produce: a dot-prefixed hidden file ending `.part` or `.integrity-check`.
    $stalePart = 'dists/'.$pkg->id.'/.somefile.zip.ABCDEFGH.part';
    $disk->put($stalePart, 'leftover-part-bytes');
    $staleIntegrity = 'tarballs/'.$pkg->id.'/.pkg-1.0.0.tgz.ABCDEFGH.integrity-check';
    $disk->put($staleIntegrity, 'leftover-integrity-bytes');

    Http::fake([
        '*/p2/acme/demo.json' => Http::response([
            'packages' => ['acme/demo' => MetadataMinifier::minify([])],
        ], 200),
    ]);

    app(MirrorImporter::class)->import($pkg, $source);

    $disk->assertMissing($stalePart);
    $disk->assertMissing($staleIntegrity);
    $disk->assertExists($realPath);
});

it('sweeps stale pypi staging leftovers for a python mirror package too', function () {
    Storage::fake('artifacts');
    $disk = Storage::disk('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Python]);
    $pkg = mirrorImporterOrphanPackage($group, $source, 'acme-demo', PackageType::Python);

    $stalePart = 'pypi/'.$pkg->id.'/.acme_demo-1.0.0.tar.gz.ABCDEFGH.part';
    $disk->put($stalePart, 'leftover-bytes');

    Http::fake([
        '*/simple/acme-demo/' => Http::response(['files' => []], 200),
    ]);

    app(MirrorImporter::class)->import($pkg, $source);

    $disk->assertMissing($stalePart);
});

it('does not touch a staging-like file that is not hidden or does not carry a staging suffix', function () {
    Storage::fake('artifacts');
    $disk = Storage::disk('artifacts');
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $source = MirrorSource::factory()->create(['organization_id' => $group->organization_id, 'url' => 'https://repo.test', 'type' => PackageType::Composer]);
    $pkg = mirrorImporterOrphanPackage($group, $source, 'acme/demo', PackageType::Composer);

    // Not dot-prefixed — a real dist file must never be swept even if it happens to end
    // similarly.
    $visible = 'dists/'.$pkg->id.'/keepme.part';
    $disk->put($visible, 'kept-bytes');
    // Dot-prefixed but no recognised staging suffix.
    $unrelatedHidden = 'dists/'.$pkg->id.'/.gitkeep';
    $disk->put($unrelatedHidden, '');

    Http::fake([
        '*/p2/acme/demo.json' => Http::response([
            'packages' => ['acme/demo' => MetadataMinifier::minify([])],
        ], 200),
    ]);

    app(MirrorImporter::class)->import($pkg, $source);

    $disk->assertExists($visible);
    $disk->assertExists($unrelatedHidden);
});
