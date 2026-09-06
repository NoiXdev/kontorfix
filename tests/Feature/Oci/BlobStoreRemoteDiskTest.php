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

    // Sizes chosen so the parts' offsets — 0, 5, 15 — sort DIFFERENTLY as strings than as
    // numbers: lexicographically, "15" < "5" (comparing the first character, '1' < '5'),
    // so a naive `sort()` of ["0", "5", "15"] yields ["0", "15", "5"] — wrong order. Equal
    // or round-number chunk sizes (e.g. 1000/1000/500 → offsets 0/1000/2000) would not
    // catch this: every one of those offsets happens to sort identically both ways, so a
    // reassembly bug that reads parts back in lexicographic rather than numeric order
    // would pass unnoticed. See the mutation this is meant to catch in the class docblock
    // above and in the Task 4 review notes.
    $chunks = [str_repeat('x', 5), str_repeat('y', 10), str_repeat('z', 7)];
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
        ->and($blob->size)->toBe(22)
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
