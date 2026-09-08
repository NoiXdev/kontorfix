<?php

use App\Models\OciBlob;
use App\Models\OciBlobUpload;
use App\Models\OciManifest;
use App\Models\OciTag;
use App\Models\Organization;
use App\Models\Package;
use App\Models\SystemSetting;
use App\Services\Oci\Sweeper\OciSweeper;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('artifacts');
    $this->travelTo('2026-09-08 12:00:00');
});

/** An oci_blobs row with a real file behind it, aged $ageHours into the past. */
function sweeperBlob(Package $package, string $digest, int $ageHours): OciBlob
{
    $path = 'docker/blobs/'.$package->organization_id.'/'.$digest;
    Storage::disk('artifacts')->put($path, 'x');

    // organization_id passed explicitly: OciBlobFactory mints its OWN organization by
    // default, and a blob in an organization no package belongs to is unreachable for a
    // reason that has nothing to do with what any test here asserts.
    return OciBlob::factory()->create([
        'organization_id' => $package->organization_id,
        'digest' => $digest,
        'size' => 1,
        'path' => $path,
        'created_at' => now()->subHours($ageHours),
    ]);
}

/** A tagged manifest naming $layerDigest, so the blob of that digest is reachable. */
function sweeperTaggedManifest(Package $package, string $layerDigest): OciManifest
{
    $bytes = json_encode(['layers' => [['digest' => $layerDigest]]], JSON_THROW_ON_ERROR);

    $manifest = OciManifest::factory()->for($package)->create([
        'digest' => 'sha256:'.hash('sha256', $bytes),
        'payload' => $bytes,
        'size' => strlen($bytes),
    ]);

    OciTag::factory()->create([
        'package_id' => $package->id,
        'name' => 'latest',
        'manifest_id' => $manifest->id,
    ]);

    return $manifest;
}

it('removes an unreachable blob older than the grace period and its file', function () {
    $package = Package::factory()->docker()->create();
    $stale = sweeperBlob($package, 'sha256:stale', 48);

    $report = app(OciSweeper::class)->sweep(1000);

    expect(OciBlob::whereKey($stale->id)->exists())->toBeFalse()
        ->and(Storage::disk('artifacts')->exists($stale->path))->toBeFalse()
        ->and($report->blobsRemoved)->toBe(1)
        ->and($report->bytesReclaimed)->toBe(1);
});

it('holds an unreachable blob inside the grace period, and says how many it holds', function () {
    // The same fixture as above with only the age changed — both directions off one shape.
    // A sweeper with no cutoff at all passes the previous test and fails this one; a test
    // asserting only the removal side proves nothing about the guard.
    $package = Package::factory()->docker()->create();
    $fresh = sweeperBlob($package, 'sha256:fresh', 2);

    $report = app(OciSweeper::class)->sweep(1000);

    expect(OciBlob::whereKey($fresh->id)->exists())->toBeTrue()
        ->and(Storage::disk('artifacts')->exists($fresh->path))->toBeTrue()
        ->and($report->blobsRemoved)->toBe(0)
        // The visible evidence the mechanism is working. 0 here would be indistinguishable
        // from "there was nothing to hold".
        ->and($report->blobsHeldByGrace)->toBe(1);
});

it('honours a shortened grace period', function () {
    SystemSetting::current()->update(['oci_blob_grace_hours' => 1]);
    $package = Package::factory()->docker()->create();
    sweeperBlob($package, 'sha256:zwei-stunden', 2);

    expect(app(OciSweeper::class)->sweep(1000)->blobsRemoved)->toBe(1);
});

it('keeps a reachable blob however old it is', function () {
    $package = Package::factory()->docker()->create();
    sweeperTaggedManifest($package, 'sha256:live');
    $live = sweeperBlob($package, 'sha256:live', 10_000);

    app(OciSweeper::class)->sweep(1000);

    expect(OciBlob::whereKey($live->id)->exists())->toBeTrue()
        ->and(Storage::disk('artifacts')->exists($live->path))->toBeTrue();
});

it('keeps a blob shared by two repositories when only one loses its tags', function () {
    // The deduplication claim, at the sweeper rather than at the reachability layer: the
    // blob row is organization-scoped, so as long as ANY package's tag reaches the digest,
    // the bytes stay.
    $organization = Organization::factory()->create();
    $a = Package::factory()->docker()->for($organization)->create(['name' => 'a']);
    $b = Package::factory()->docker()->for($organization)->create(['name' => 'b']);

    // a's manifest is untagged — a "lost its tags" to retention. b still tags the digest.
    $bytes = json_encode(['layers' => [['digest' => 'sha256:base']]], JSON_THROW_ON_ERROR);
    OciManifest::factory()->for($a)->create([
        'digest' => 'sha256:'.hash('sha256', $bytes),
        'payload' => $bytes,
        'size' => strlen($bytes),
        'created_at' => now()->subHours(48),
    ]);
    sweeperTaggedManifest($b, 'sha256:base');

    $shared = sweeperBlob($a, 'sha256:base', 48);

    app(OciSweeper::class)->sweep(1000);

    expect(OciBlob::whereKey($shared->id)->exists())->toBeTrue();
});

it('removes an unreachable manifest older than the grace period, and never a tagged one', function () {
    $package = Package::factory()->docker()->create();
    $orphan = OciManifest::factory()->for($package)->create([
        'payload' => '{"layers":[]}',
        'created_at' => now()->subHours(48),
    ]);
    $tagged = OciManifest::factory()->for($package)->create([
        'payload' => '{"layers":[]}',
        'created_at' => now()->subHours(48),
    ]);
    OciTag::factory()->create(['package_id' => $package->id, 'name' => 'latest', 'manifest_id' => $tagged->id]);

    $report = app(OciSweeper::class)->sweep(1000);

    expect(OciManifest::whereKey($orphan->id)->exists())->toBeFalse()
        ->and(OciManifest::whereKey($tagged->id)->exists())->toBeTrue()
        ->and($report->manifestsRemoved)->toBe(1);
});

it('holds a fresh untagged child manifest, because buildx writes children before the index', function () {
    // `buildx --push` writes every child manifest of a multi-arch image by digest and the
    // tagged index LAST — every child is unreachable in between, which is why manifests
    // need the grace period as much as blobs do.
    $package = Package::factory()->docker()->create();
    $child = OciManifest::factory()->for($package)->create([
        'payload' => '{"layers":[]}',
        'created_at' => now()->subMinutes(5),
    ]);

    app(OciSweeper::class)->sweep(1000);

    expect(OciManifest::whereKey($child->id)->exists())->toBeTrue();
});

it('removes an expired upload session and its partial file', function () {
    $package = Package::factory()->docker()->create();
    Storage::disk('artifacts')->put('docker/uploads/abc', 'partial');
    $upload = OciBlobUpload::create([
        'package_id' => $package->id,
        'path' => 'docker/uploads/abc',
        'offset' => 7,
        'expires_at' => now()->subMinute(),
    ]);

    $report = app(OciSweeper::class)->sweep(1000);

    expect(OciBlobUpload::whereKey($upload->id)->exists())->toBeFalse()
        ->and(Storage::disk('artifacts')->exists('docker/uploads/abc'))->toBeFalse()
        ->and($report->uploadsRemoved)->toBe(1);
});

it('keeps a session a client may still resume', function () {
    $package = Package::factory()->docker()->create();
    $upload = OciBlobUpload::create([
        'package_id' => $package->id,
        'path' => 'docker/uploads/def',
        'offset' => 0,
        // expires_at, NOT the grace period: the two protect different things, and tying
        // the sweep to the grace period would mean lowering it silently shortens how long
        // a client may resume an upload.
        'expires_at' => now()->addHour(),
    ]);

    app(OciSweeper::class)->sweep(1000);

    expect(OciBlobUpload::whereKey($upload->id)->exists())->toBeTrue();
});

it('removes an empty push-created repository', function () {
    $package = Package::factory()->docker()->create([
        'name' => 'abgebrochen',
        'auto_created_at' => now()->subHours(48),
    ]);

    $report = app(OciSweeper::class)->sweep(1000);

    expect(Package::whereKey($package->id)->exists())->toBeFalse()
        ->and($report->repositoriesRemoved)->toBe(1);
});

it('never removes an empty repository an operator registered', function () {
    // The whole reason auto_created_at exists: same emptiness, same age, different
    // provenance — and deleting a deliberately pre-registered repository is a worse
    // failure than leaving a broken push's leftover.
    $package = Package::factory()->docker()->create(['name' => 'vorbereitet', 'auto_created_at' => null]);
    $package->forceFill(['created_at' => now()->subYear()])->save();

    app(OciSweeper::class)->sweep(1000);

    expect(Package::whereKey($package->id)->exists())->toBeTrue();
});

it('never removes a push-created repository that holds an image', function () {
    $package = Package::factory()->docker()->create(['auto_created_at' => now()->subHours(48)]);
    sweeperTaggedManifest($package, 'sha256:whatever');

    app(OciSweeper::class)->sweep(1000);

    expect(Package::whereKey($package->id)->exists())->toBeTrue();
});

it('never removes a push-created repository holding only an untagged manifest', function () {
    // Between "first blob" and "tag written" a real push's repository has manifests but no
    // tag — buildx child manifests, again. Emptiness must mean NO manifests AND no tags,
    // not "nothing pullable yet".
    $package = Package::factory()->docker()->create(['auto_created_at' => now()->subHours(48)]);
    OciManifest::factory()->for($package)->create(['payload' => '{"layers":[]}']);

    app(OciSweeper::class)->sweep(1000);

    expect(Package::whereKey($package->id)->exists())->toBeTrue();
});

it('never removes a push-created repository whose upload session may still be resumed', function () {
    // Not redundant with the age check: begin() writes a fixed 24-hour expires_at while
    // the grace period is configurable down to one hour, so a resumable session can
    // outlive the cutoff — and deleting the package would cascade the session away
    // underneath a client still entitled to resume it.
    SystemSetting::current()->update(['oci_blob_grace_hours' => 1]);
    $package = Package::factory()->docker()->create(['auto_created_at' => now()->subHours(2)]);
    OciBlobUpload::create([
        'package_id' => $package->id,
        'path' => 'docker/uploads/ghi',
        'offset' => 0,
        'expires_at' => now()->addHours(22),
    ]);

    app(OciSweeper::class)->sweep(1000);

    expect(Package::whereKey($package->id)->exists())->toBeTrue();
});

it('stops at the blob limit and reports what it left', function () {
    $package = Package::factory()->docker()->create();
    foreach (range(1, 5) as $i) {
        sweeperBlob($package, 'sha256:stale'.$i, 48);
    }

    $report = app(OciSweeper::class)->sweep(2);

    expect($report->blobsRemoved)->toBe(2)
        // A silent cap reads as "everything is clean". 3, not 0 and not 5.
        ->and($report->blobsRemaining)->toBe(3)
        ->and(OciBlob::count())->toBe(3);
});

it('counts without removing anything in pending()', function () {
    $package = Package::factory()->docker()->create();
    sweeperBlob($package, 'sha256:stale', 48);
    sweeperBlob($package, 'sha256:fresh', 2);
    OciManifest::factory()->for($package)->create(['payload' => '{"layers":[]}', 'created_at' => now()->subHours(48)]);
    Storage::disk('artifacts')->put('docker/uploads/jkl', 'partial');
    OciBlobUpload::create([
        'package_id' => $package->id, 'path' => 'docker/uploads/jkl', 'offset' => 0,
        'expires_at' => now()->subMinute(),
    ]);
    Package::factory()->docker()->create(['auto_created_at' => now()->subHours(48)]);

    $report = app(OciSweeper::class)->pending();

    expect($report->blobsRemaining)->toBe(1)
        ->and($report->blobsHeldByGrace)->toBe(1)
        ->and($report->manifestsRemoved)->toBe(1)
        ->and($report->uploadsRemoved)->toBe(1)
        ->and($report->repositoriesRemoved)->toBe(1)
        ->and($report->blobsRemoved)->toBe(0)
        // Nothing may actually be gone. pending() is what the admin page calls on every
        // GET; a pending() that sweeps is a delete on page load.
        ->and(OciBlob::count())->toBe(2)
        ->and(OciManifest::count())->toBe(1)
        ->and(OciBlobUpload::count())->toBe(1)
        ->and(Package::count())->toBe(2)
        ->and(Storage::disk('artifacts')->exists('docker/uploads/jkl'))->toBeTrue();
});

it('logs one summary per real sweep and nothing for a sweep with no work', function () {
    $package = Package::factory()->docker()->create();
    sweeperBlob($package, 'sha256:stale', 48);

    app(OciSweeper::class)->sweep(1000);
    expect(Activity::where('log_name', 'oci')->where('event', 'storage_swept')->count())->toBe(1);

    app(OciSweeper::class)->sweep(1000);
    // No entry for a no-op: a nightly "nothing happened" per day buries the entries that
    // matter.
    expect(Activity::where('log_name', 'oci')->where('event', 'storage_swept')->count())->toBe(1);
});

it('warns when the command hits its budget, and reports each count', function () {
    $package = Package::factory()->docker()->create();
    foreach (range(1, 3) as $i) {
        sweeperBlob($package, 'sha256:stale'.$i, 48);
    }

    $this->artisan('oci:sweep --limit=2')
        ->expectsOutputToContain('Budget erreicht')
        ->assertSuccessful();

    expect(OciBlob::count())->toBe(1);

    // Under the budget: no warning.
    $this->artisan('oci:sweep --limit=100')
        ->doesntExpectOutputToContain('Budget erreicht')
        ->assertSuccessful();

    expect(OciBlob::count())->toBe(0);
});
