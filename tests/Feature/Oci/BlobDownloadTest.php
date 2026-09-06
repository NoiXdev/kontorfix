<?php

use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Oci\Digest;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as LeagueFilesystem;
use Tests\Support\InMemoryFilesystemAdapter;

/**
 * A Basic-auth Authorization header, as a $server-vars array rather than a headers array.
 * Mirrors basicAuthServerVars() in tests/Feature/Oci/BlobUploadTest.php — kept as its own
 * copy under a distinct name rather than shared, the same way ManifestTest.php's
 * manifestAuthServerVars() does, because Pest requires every *Test.php file in one PHP
 * process for this project's serial (never --parallel) run, and two files declaring the
 * same top-level function name would be a fatal redeclaration rather than a merge.
 *
 * @return array<string, string>
 */
function blobDownloadAuthServerVars(Group $group, TokenAbility $ability): array
{
    $plain = tokenPlainTextFor($group, $ability);

    return ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('x:'.$plain)];
}

/**
 * Pushes the given bytes as a blob into a repository of a SEPARATE organization/registry —
 * used to prove GET never serves a blob held by another tenant. Mirrors
 * uploadIntoOtherOrganization() in BlobUploadTest.php under a distinct name (see above).
 */
function uploadBlobToOtherOrganization(string $bytes): void
{
    $org = Organization::factory()->create(['enabled_registry_types' => ['docker']]);
    $group = Group::factory()->for($org)->create();
    Domain::create(['group_id' => $group->id, 'hostname' => 'foreign.test']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Docker, 'name' => 'app']);
    $group->packages()->attach($pkg);

    $publish = blobDownloadAuthServerVars($group, TokenAbility::Publish);
    $digest = Digest::of($bytes);

    test()->withServerVariables($publish)
        ->call('POST', "http://foreign.test/v2/app/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(201);
}

/**
 * Swaps the artifacts disk for an in-memory adapter that is deliberately NOT
 * League\Flysystem\Local\LocalFilesystemAdapter — standing in for S3 so
 * BlobStore::isLocalDisk() takes the remote branch, and BlobController::show() the redirect
 * branch rather than the streaming one. Mirrors useInMemoryArtifactsDisk() in
 * BlobStoreRemoteDiskTest.php, kept as its own copy for the same cross-file-redeclaration
 * reason blobDownloadAuthServerVars() above states.
 */
function useRemoteArtifactsDisk(): void
{
    $adapter = new InMemoryFilesystemAdapter;
    $disk = new FilesystemAdapter(new LeagueFilesystem($adapter), $adapter);

    Storage::set('artifacts', $disk);
}

/**
 * Pushes $bytes as a monolithic blob to repository "app" over HTTP and returns its digest.
 *
 * @param  array<string, string>  $serverVars
 */
function pushBlobToApp(array $serverVars, string $bytes): string
{
    $digest = Digest::of($bytes);

    test()->withServerVariables($serverVars)
        ->call('POST', "http://images.test/v2/app/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(201);

    return $digest;
}

beforeEach(function () {
    Storage::fake('artifacts');

    $this->org = Organization::factory()->create([
        'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
    ]);
    $this->group = Group::factory()->for($this->org)->create();
    Domain::create(['group_id' => $this->group->id, 'hostname' => 'images.test']);

    $app = Package::factory()->inOrgOf($this->group)->create(['type' => PackageType::Docker, 'name' => 'app']);
    $this->group->packages()->attach($app);

    $this->publish = blobDownloadAuthServerVars($this->group, TokenAbility::Publish);
    $this->read = blobDownloadAuthServerVars($this->group, TokenAbility::Read);
});

it('streams a blob when the storage backend is local', function () {
    // StorageSetting::current()->driver === 'local' in the test environment, and
    // Storage::fake('artifacts') (see beforeEach) is a genuinely local Flysystem adapter
    // under the hood, so BlobStore::isLocalDisk() takes the streaming branch.
    $bytes = random_bytes(4096);
    $digest = pushBlobToApp($this->publish, $bytes);

    $response = $this->withServerVariables($this->read)
        ->call('GET', "http://images.test/v2/app/blobs/{$digest}");

    $response->assertOk()
        ->assertHeader('Docker-Content-Digest', $digest)
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('Content-Length', (string) strlen($bytes));

    // The point of streaming rather than reading the file into memory is invisible from
    // the outside except in the bytes actually delivered — this is what proves the chunked
    // fread()/echo loop in BlobController::show() moved the WHOLE file, not merely the
    // first chunk or a truncated read.
    expect($response->streamedContent())->toBe($bytes);
});

it('redirects to a presigned url when the storage backend is s3', function () {
    // The point of the redirect is that PHP never touches a payload byte — asserting the
    // bytes would mean following the presigned URL, which tests S3's own temporaryUrl()
    // implementation rather than this controller. There is no real bucket available to
    // this suite, so the artifacts disk is swapped for an adapter that reports itself as
    // something other than local storage (see useRemoteArtifactsDisk()), exactly the
    // property BlobStore::isLocalDisk() inspects, rather than merely flipping a
    // StorageSetting config value BlobController::show() does not actually read.
    $this->freezeTime();
    useRemoteArtifactsDisk();

    $bytes = random_bytes(256);
    $digest = pushBlobToApp($this->publish, $bytes);

    $path = Digest::pathFor((string) $this->org->id, $digest);
    $expectedUrl = 'https://fake-s3.test/'.$path.'?expires='.now()->addMinutes(5)->getTimestamp();

    $this->withServerVariables($this->read)
        ->call('GET', "http://images.test/v2/app/blobs/{$digest}")
        ->assertStatus(302)
        ->assertHeader('Location', $expectedUrl);
});

it('404s a blob belonging to another organization', function () {
    // The same bytes held by a different organization must read as absent here — a
    // global digest index would turn this endpoint into an existence oracle, exactly the
    // property the sibling HEAD test in BlobUploadTest.php already guards for HEAD. GET
    // shares the same find() call, but is not exercised by that file at all, so this is
    // the only test in the suite that would notice a GET-specific regression here (e.g.
    // an accidental digest-only lookup added only to the streaming/redirect branch).
    $bytes = random_bytes(1024);
    $digest = Digest::of($bytes);
    uploadBlobToOtherOrganization($bytes);

    $this->withServerVariables($this->read)
        ->call('GET', "http://images.test/v2/app/blobs/{$digest}")
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'BLOB_UNKNOWN');
});

it('refuses an anonymous read with 401 and the basic challenge', function () {
    $bytes = random_bytes(64);
    $digest = pushBlobToApp($this->publish, $bytes);

    // withServerVariables() mutates $this->serverVariables on the test case directly
    // rather than returning a scoped copy (see vendor MakesHttpRequests::withServerVariables())
    // — pushBlobToApp() above leaves the publish token attached to every later call() on
    // $this unless explicitly cleared. Reset to an empty set of server variables so this
    // request is genuinely anonymous, not merely unlabelled.
    $this->withServerVariables([])
        ->call('GET', "http://images.test/v2/app/blobs/{$digest}")
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="kontorfix"');
});
