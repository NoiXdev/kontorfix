<?php

use App\Enums\PackageType;
use App\Exceptions\OciException;
use App\Models\Group;
use App\Models\OciBlob;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Oci\BlobStore;
use App\Services\Oci\Digest;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as LeagueFilesystem;
use Tests\Support\InMemoryFilesystemAdapter;

/**
 * BlobStore::isLocalDisk() decides its whole storage strategy on the resolved Flysystem
 * adapter's class. The rest of the suite only ever exercises the genuinely-local branch
 * (Storage::fake('artifacts') is still a real local adapter under the hood) — this file
 * swaps in an adapter that is deliberately NOT League\Flysystem\Local\LocalFilesystemAdapter,
 * standing in for S3 without needing a real bucket, so the "cannot append in place"
 * fallback gets run and asserted on rather than only read.
 */
function useInMemoryArtifactsDisk(): InMemoryFilesystemAdapter
{
    $adapter = new InMemoryFilesystemAdapter;
    $disk = new FilesystemAdapter(new LeagueFilesystem($adapter), $adapter);

    Storage::set('artifacts', $disk);

    return $adapter;
}

it('writes each chunk as its own object instead of rewriting the accumulated upload', function () {
    $adapter = useInMemoryArtifactsDisk();
    $package = Package::factory()->create(['type' => PackageType::Docker]);
    $store = app(BlobStore::class);

    $chunks = [str_repeat('a', 100), str_repeat('b', 200), str_repeat('c', 50)];
    $upload = $store->begin($package);

    foreach ($chunks as $chunk) {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $chunk);
        rewind($stream);
        $store->append($upload, $stream);
        fclose($stream);
    }

    // The whole point of the fix: each of the 3 chunks above must have moved ONLY its own
    // bytes. A read-modify-write of the accumulated file would have written 100, then
    // 300 (100+200), then 350 (100+200+50) — growing with every call.
    expect($adapter->writeSizes)->toBe([100, 200, 50]);
});

it('reassembles remote parts, in order, into a correct blob on finish', function () {
    useInMemoryArtifactsDisk();
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->for($org)->create(['type' => PackageType::Docker]);
    $group->packages()->attach($package);
    $store = app(BlobStore::class);

    $chunks = [str_repeat('x', 1000), str_repeat('y', 1000), str_repeat('z', 500)];
    $digest = Digest::of(implode('', $chunks));

    $upload = $store->begin($package);
    foreach ($chunks as $chunk) {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $chunk);
        rewind($stream);
        $store->append($upload, $stream);
        fclose($stream);
    }

    $blob = $store->finish($upload, $digest);

    expect($blob->digest)->toBe($digest)
        ->and($blob->size)->toBe(2500)
        ->and($blob->organization_id)->toBe($org->id)
        ->and(Storage::disk('artifacts')->get($blob->path))->toBe(implode('', $chunks));
});

it('deletes every part and writes no blob row on a remote mismatch', function () {
    useInMemoryArtifactsDisk();
    $package = Package::factory()->create(['type' => PackageType::Docker]);
    $store = app(BlobStore::class);

    $upload = $store->begin($package);
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, 'not what was announced');
    rewind($stream);
    $store->append($upload, $stream);
    fclose($stream);

    $lie = 'sha256:'.str_repeat('f', 64);

    expect(fn () => $store->finish($upload, $lie))->toThrow(OciException::class);

    expect(OciBlob::count())->toBe(0)
        ->and(Storage::disk('artifacts')->allFiles($upload->path))->toBe([]);
});
