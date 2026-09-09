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
 *
 * LOAD-BEARING SIDE EFFECT: withServerVariables() mutates $this->serverVariables on the
 * TestCase directly (it is not scoped to the one call() above), so this leaves the
 * FOREIGN organization's publish token attached to every later call() the caller makes on
 * $this. Every caller MUST call withServerVariables() again with its own credentials
 * before asserting anything, or it is unknowingly testing with the wrong tenant's token.
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
 * Pushes $bytes as a monolithic blob to repository "app" over HTTP, THEN publishes a
 * manifest of "app" that names the digest as a layer, and returns the digest.
 *
 * The manifest is not decoration. BlobController::show() serves a blob to a READ token
 * only when one of this repository's own manifests names it — the repository's own
 * boundary, since oci_blobs deduplicates per organization and carries none of its own.
 * A real `docker pull` always fetches the manifest first and only then the blobs it names,
 * so this helper reproduces the state a pull actually finds. Without it these tests pushed
 * a blob no manifest referenced and then pulled it, a shape no client produces.
 *
 * @param  array<string, string>  $serverVars  a PUBLISH token's — both requests write
 */
function pushBlobToApp(array $serverVars, string $bytes): string
{
    $digest = Digest::of($bytes);

    test()->withServerVariables($serverVars)
        ->call('POST', "http://images.test/v2/app/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(201);

    referenceBlobFromManifest($serverVars, $digest);

    return $digest;
}

/**
 * Publishes a manifest of repository "app" naming $digest as its single layer.
 *
 * The tag is derived from the digest so that two blobs pushed in one test do not overwrite
 * each other's manifest — each blob gets its own, and both stay referenced.
 *
 * @param  array<string, string>  $serverVars  a PUBLISH token's
 */
function referenceBlobFromManifest(array $serverVars, string $digest): void
{
    $payload = json_encode([
        'schemaVersion' => 2,
        'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
        'layers' => [['mediaType' => 'application/vnd.oci.image.layer.v1.tar', 'digest' => $digest, 'size' => 1]],
    ]);

    test()->withServerVariables($serverVars + ['CONTENT_TYPE' => 'application/vnd.oci.image.manifest.v1+json'])
        ->call('PUT', 'http://images.test/v2/app/manifests/t'.substr($digest, 7, 12), content: (string) $payload)
        ->assertStatus(201);
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

it('answers BLOB_UNKNOWN rather than a truncated 200 when the blob row has outlived its file', function () {
    // Simulates an oci_blobs row surviving without its bytes on disk — a database restore
    // older than the artifacts volume, or an operator repointing the artifacts root, both
    // leave exactly this shape behind. BlobStore::readStream() must report it as "not
    // found" and BlobController::show() must check that BEFORE constructing the
    // StreamedResponse, so the client gets a normal OCI error body instead of a 200 with a
    // promised Content-Length and a body that silently ends short.
    $bytes = random_bytes(64);
    $digest = pushBlobToApp($this->publish, $bytes);

    $path = Digest::pathFor((string) $this->org->id, $digest);
    Storage::disk('artifacts')->delete($path);

    $this->withServerVariables($this->read)
        ->call('GET', "http://images.test/v2/app/blobs/{$digest}")
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'BLOB_UNKNOWN');
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

it('answers HEAD with headers only even when the storage backend is s3, never a redirect', function () {
    // HEAD must stay exactly what it was before this task's storage-backend branching
    // existed — a plain 200 with Content-Length and Docker-Content-Digest, regardless of
    // which disk is configured. `docker pull` sends HEAD first and reads those headers
    // from THIS response to decide whether it already has the layer; a 302 here breaks
    // that. Worse: it would hand a presigned object URL — real, temporary read access to
    // the bytes — to anyone who merely asked whether a blob exists, without the request
    // ever being a GET.
    useRemoteArtifactsDisk();

    $bytes = random_bytes(64);
    $digest = pushBlobToApp($this->publish, $bytes);

    $this->withServerVariables($this->read)
        ->call('HEAD', "http://images.test/v2/app/blobs/{$digest}")
        ->assertStatus(200)
        ->assertHeader('Content-Length', (string) strlen($bytes))
        ->assertHeader('Docker-Content-Digest', $digest)
        ->assertHeaderMissing('Location');
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

    // Named by a manifest of THIS repository, so the org boundary is the only thing left
    // that can refuse the request. Without this the test would pass even if find() were
    // replaced by a global digest lookup, because the second gate in show() — "one of this
    // repository's own manifests names it" — would refuse an unreferenced digest anyway.
    // The point of this case is the FIRST gate, so the second one is satisfied on purpose.
    referenceBlobFromManifest($this->publish, $digest);

    // Load-bearing: uploadBlobToOtherOrganization() leaves the FOREIGN organization's
    // publish token attached to $this (see its own docblock) — this call resets to
    // $this->read, this organization's own read token, before the request below. Without
    // it, the request would run authenticated as the wrong tenant while the test still
    // claimed to be checking this one.
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

it('does not serve an unreferenced blob of the same organization to an anonymous caller on a public registry', function () {
    // THE REGRESSION THIS CASE EXISTS FOR. show()'s second gate used to be scoped to
    // `$package->shared`, on the premise that every reader of an ordinary repository is a
    // member of the blob's own organization and so there is no other tenant to disclose it
    // to. A reader of a PUBLIC group is a member of nothing: ociRepository() waves an
    // anonymous caller through for a public group, exactly as npm/composer/pypi do. With
    // the gate skipped, `blobs->find($package->organization_id, $digest)` was the only
    // check left — and it is satisfied by every blob the organization owns, including the
    // layers of PRIVATE repositories in OTHER registries. No shared package was needed,
    // and no token at all.
    $this->group->update(['public' => true]);

    // A second, non-public registry of the SAME organization, with its own repository and
    // its own layer. Nothing about it is shared with anyone.
    $secret = Group::factory()->for($this->org)->create(['public' => false]);
    Domain::create(['group_id' => $secret->id, 'hostname' => 'secret.test']);
    $secretPkg = Package::factory()->inOrgOf($secret)->create(['type' => PackageType::Docker, 'name' => 'internal']);
    $secret->packages()->attach($secretPkg);

    $bytes = random_bytes(2048);
    $digest = Digest::of($bytes);
    $this->withServerVariables(blobDownloadAuthServerVars($secret, TokenAbility::Publish))
        ->call('POST', "http://secret.test/v2/internal/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(201);

    // Anonymous — withServerVariables() mutates the test case, so the publish token above
    // stays attached to every later call() until it is explicitly cleared.
    $this->withServerVariables([])
        ->call('GET', "http://images.test/v2/app/blobs/{$digest}")
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'BLOB_UNKNOWN');
});

it('does not serve an unreferenced blob of the same organization to a read token', function () {
    // The same rule for the ordinary, authenticated case: holding a read token for one
    // repository is not entitlement to every layer the organization has ever stored. This
    // is the non-public sibling of the case above, and it is what makes the gate a property
    // of the repository rather than a special case for anonymous callers.
    $secret = Group::factory()->for($this->org)->create();
    Domain::create(['group_id' => $secret->id, 'hostname' => 'secret.test']);
    $secretPkg = Package::factory()->inOrgOf($secret)->create(['type' => PackageType::Docker, 'name' => 'internal']);
    $secret->packages()->attach($secretPkg);

    $bytes = random_bytes(2048);
    $digest = Digest::of($bytes);
    $this->withServerVariables(blobDownloadAuthServerVars($secret, TokenAbility::Publish))
        ->call('POST', "http://secret.test/v2/internal/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(201);

    $this->withServerVariables($this->read)
        ->call('GET', "http://images.test/v2/app/blobs/{$digest}")
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'BLOB_UNKNOWN');
});

it('still answers a publish token probing an unreferenced blob, which is what a push does', function () {
    // The one legitimate caller for a blob no manifest names yet: a client asking whether
    // a layer is already here BEFORE it uploads it, and therefore long before it writes the
    // manifest that will name it. That caller always holds a publish token, and a caller
    // who may write into this registry learns nothing from an existence probe it could not
    // learn by pushing. If this case turns red, `docker push` re-uploads every layer it
    // already has — a silent, expensive regression no other test would catch.
    $bytes = random_bytes(512);
    $digest = Digest::of($bytes);

    $this->withServerVariables($this->publish)
        ->call('POST', "http://images.test/v2/app/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(201);

    // Deliberately NO manifest: this is exactly the window the exception exists for.
    $this->withServerVariables($this->publish)
        ->call('HEAD', "http://images.test/v2/app/blobs/{$digest}")
        ->assertStatus(200)
        ->assertHeader('Docker-Content-Digest', $digest);

    // And the same token's GET, so the exception is not accidentally HEAD-only — the two
    // must agree about what exists.
    $this->withServerVariables($this->publish)
        ->call('GET', "http://images.test/v2/app/blobs/{$digest}")
        ->assertOk();
});
