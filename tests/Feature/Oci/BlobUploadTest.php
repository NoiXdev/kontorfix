<?php

use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Domain;
use App\Models\Group;
use App\Models\OciBlob;
use App\Models\OciBlobUpload;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Oci\BlobStore;
use App\Services\Oci\Digest;
use Illuminate\Support\Facades\Storage;

/**
 * A Basic-auth Authorization header, as a $server-vars array rather than a headers array.
 *
 * withHeaders()'s `defaultHeaders` is only ever read by the convenience request methods
 * (post()/head()/etc via transformHeadersToServerVars()) — the raw call() this file needs
 * for PATCH/PUT with a genuine binary body never consults it, so a token set via
 * withHeaders() would silently vanish on exactly the requests this protocol is made of.
 * withServerVariables() IS honoured by call() (and by every convenience method, since they
 * all funnel into it), so every request in this file goes through it instead.
 *
 * @return array<string, string>
 */
function basicAuthServerVars(Group $group, TokenAbility $ability): array
{
    $plain = tokenPlainTextFor($group, $ability);

    return ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('x:'.$plain)];
}

beforeEach(function () {
    Storage::fake('artifacts');

    $this->org = Organization::factory()->create([
        'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
    ]);
    $this->group = Group::factory()->for($this->org)->create();
    Domain::create(['group_id' => $this->group->id, 'hostname' => 'images.test']);

    $app = Package::factory()->inOrgOf($this->group)->create(['type' => PackageType::Docker, 'name' => 'app']);
    $other = Package::factory()->inOrgOf($this->group)->create(['type' => PackageType::Docker, 'name' => 'other']);
    $this->group->packages()->attach($app);
    $this->group->packages()->attach($other);

    $this->publish = basicAuthServerVars($this->group, TokenAbility::Publish);
    $this->read = basicAuthServerVars($this->group, TokenAbility::Read);
});

/**
 * Pushes the given bytes as a blob into a repository of a SEPARATE organization/registry —
 * used to prove HEAD never reports a blob held by another tenant.
 *
 * LOAD-BEARING SIDE EFFECT: withServerVariables() mutates $this->serverVariables on the
 * TestCase directly (it is not scoped to the one call() above), so this leaves the
 * FOREIGN organization's publish token attached to every later call() the caller makes on
 * $this. Every caller MUST call withServerVariables() again with its own credentials
 * before asserting anything, or it is unknowingly testing with the wrong tenant's token.
 */
function uploadIntoOtherOrganization(string $bytes): void
{
    $org = Organization::factory()->create(['enabled_registry_types' => ['docker']]);
    $group = Group::factory()->for($org)->create();
    Domain::create(['group_id' => $group->id, 'hostname' => 'foreign.test']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => PackageType::Docker, 'name' => 'app']);
    $group->packages()->attach($pkg);

    $publish = basicAuthServerVars($group, TokenAbility::Publish);
    $digest = Digest::of($bytes);

    test()->withServerVariables($publish)
        ->call('POST', "http://foreign.test/v2/app/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(201);
}

/**
 * A Docker package owned by a SEPARATE organization, marked shared and attached to
 * $group — the one shape that makes `RegistryAccessService::packagesFor($group)` return a
 * repository whose `organization_id` differs from $group's own. This is the only real way
 * to reach the cross-organization branch in `BlobController::mountFrom()`: a name that is
 * merely unknown (`from=fremd`, the old version of the fallback test below) never gets far
 * enough to compare organizations at all — `packagesFor()` simply would not have returned
 * it. See `sharedPackageIn()` in tests/Feature/Registry/SharedPackageResolutionTest.php for
 * the established pattern this mirrors.
 */
function sharedDockerPackageIn(Group $group, string $name): Package
{
    $foreignOrg = Organization::factory()->create();
    $package = Package::factory()->for($foreignOrg)->create([
        'type' => PackageType::Docker, 'name' => $name, 'shared' => true,
    ]);
    $group->packages()->attach($package);

    return $package;
}

/**
 * Seeds a real OciBlob under $package's own organization, without going through HTTP —
 * used to give a shared foreign package an actual blob to (fail to) mount, so the
 * cross-organization branch is exercised with genuine content rather than a name that
 * simply does not resolve.
 */
function seedBlobFor(Package $package, string $bytes): string
{
    $digest = Digest::of($bytes);
    $store = app(BlobStore::class);
    $upload = $store->begin($package);

    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $bytes);
    rewind($stream);
    $store->append($upload, $stream);
    fclose($stream);

    $store->finish($upload, $digest);

    return $digest;
}

it('completes a monolithic upload and stores the blob', function () {
    $bytes = random_bytes(4096);
    $digest = Digest::of($bytes);

    $this->withServerVariables($this->publish)
        ->call('POST', "http://images.test/v2/app/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(201)
        ->assertHeader('Docker-Content-Digest', $digest)
        ->assertHeader('Location', "/v2/app/blobs/{$digest}");

    $blob = OciBlob::where('digest', $digest)->sole();

    expect($blob->size)->toBe(4096)
        ->and(Storage::disk('artifacts')->get($blob->path))->toBe($bytes);
});

it('completes a chunked upload across three patches', function () {
    $chunks = [random_bytes(1024), random_bytes(1024), random_bytes(512)];
    $digest = Digest::of(implode('', $chunks));

    $start = $this->withServerVariables($this->publish)->post('http://images.test/v2/app/blobs/uploads/');
    $start->assertStatus(202);
    $location = $start->headers->get('Location');

    $offset = 0;
    foreach ($chunks as $chunk) {
        $response = $this->withServerVariables($this->publish)->call('PATCH', $location, content: $chunk);
        $offset += strlen($chunk);
        // The Range header is what tells the client where to continue after an interruption.
        $response->assertStatus(202)->assertHeader('Range', '0-'.($offset - 1));
    }

    $this->withServerVariables($this->publish)
        ->call('PUT', $location."?digest={$digest}")
        ->assertStatus(201);

    expect(OciBlob::where('digest', $digest)->sole()->size)->toBe(2560);
});

it('refuses a blob whose content does not hash to its announced digest, and stores nothing', function () {
    $bytes = random_bytes(512);
    $lie = 'sha256:'.str_repeat('f', 64);

    $this->withServerVariables($this->publish)
        ->call('POST', "http://images.test/v2/app/blobs/uploads/?digest={$lie}", content: $bytes)
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'DIGEST_INVALID');

    // Both halves: the refusal AND that nothing was promoted. In a content-addressable store
    // a mismatched blob is not a failed upload, it is a poisoned entry every later reference
    // resolves to.
    expect(OciBlob::count())->toBe(0)
        ->and(Storage::disk('artifacts')->allFiles('docker'))->toBe([]);
});

it('reports an existing blob by HEAD so the client skips re-uploading it', function () {
    $bytes = random_bytes(1024);
    $digest = Digest::of($bytes);
    $this->withServerVariables($this->publish)
        ->call('POST', "http://images.test/v2/app/blobs/uploads/?digest={$digest}", content: $bytes);

    $this->withServerVariables($this->read)
        ->head("http://images.test/v2/app/blobs/{$digest}")
        ->assertOk()
        ->assertHeader('Content-Length', '1024')
        ->assertHeader('Docker-Content-Digest', $digest);
});

it('does not report another organization\'s blob, so a token cannot probe foreign contents', function () {
    // The same bytes uploaded into a different organization must read as absent here — a
    // global digest index would turn this endpoint into an existence oracle.
    $bytes = random_bytes(1024);
    $digest = Digest::of($bytes);
    uploadIntoOtherOrganization($bytes);   // helper defined in the test file

    // Load-bearing: uploadIntoOtherOrganization() leaves the FOREIGN organization's
    // publish token attached to $this (see its own docblock) — this call resets to
    // $this->read, this organization's own read token, before the request below. Without
    // it, the request would run authenticated as the wrong tenant while the test still
    // claimed to be checking this one.
    $this->withServerVariables($this->read)
        ->head("http://images.test/v2/app/blobs/{$digest}")
        ->assertStatus(404);
});

it('mounts a blob from another repository of the same organization without transferring it', function () {
    $bytes = random_bytes(2048);
    $digest = Digest::of($bytes);
    $this->withServerVariables($this->publish)
        ->call('POST', "http://images.test/v2/app/blobs/uploads/?digest={$digest}", content: $bytes);

    $filesBeforeMount = Storage::disk('artifacts')->allFiles('docker');

    $this->withServerVariables($this->publish)
        ->post("http://images.test/v2/other/blobs/uploads/?mount={$digest}&from=app")
        ->assertStatus(201)
        ->assertHeader('Docker-Content-Digest', $digest);

    // "Without transferring it" is the entire point of mounting — asserting only the
    // status and the digest header would pass unchanged for an implementation that
    // secretly copies the bytes into a second path, or that opens and quietly abandons an
    // upload session. Nothing on disk changes, the blob row is not duplicated, and no
    // session is left behind.
    expect(Storage::disk('artifacts')->allFiles('docker'))->toBe($filesBeforeMount)
        ->and(OciBlob::count())->toBe(1)
        ->and(OciBlobUpload::count())->toBe(0);
});

it('falls back to a normal upload when the mount source is in another organization', function () {
    // `from` must name a repository that GENUINELY resolves and GENUINELY holds the
    // digest, in another organization — an unknown name (the old shape of this test) only
    // ever reaches the "$source === null" branch of mountFrom(), leaving the actual
    // cross-organization comparison unexercised by the suite. A shared package is the one
    // realistic way a name can resolve here while still belonging to a different
    // organization than the target repository's own.
    $foreign = sharedDockerPackageIn($this->group, 'shared-base');
    $bytes = random_bytes(256);
    $digest = seedBlobFor($foreign, $bytes);

    // 202 rather than an error: that is the protocol's own miss path, and it leaks nothing
    // about whether the foreign blob exists.
    $this->withServerVariables($this->publish)
        ->post("http://images.test/v2/app/blobs/uploads/?mount={$digest}&from=shared-base")
        ->assertStatus(202)
        ->assertHeader('Docker-Upload-UUID');
});

it('refuses a foreign mount source even when the target already holds the same digest itself', function () {
    // The organization comparison in mountFrom() is invisible unless BOTH organizations
    // hold the exact same digest — otherwise BlobStore::mount()'s own organization-scoped
    // find() already returns null regardless of this comparison, which is exactly why the
    // test above cannot see it. Here $this->org independently already holds the digest
    // (pushed to its own "app" repository first — blobs dedupe per organization, not per
    // repository, as the mount-within-one-organization test above establishes), AND a
    // resolvable shared package in a DIFFERENT organization holds the identical bytes.
    // Without the comparison, mount() would find $this->org's OWN pre-existing blob and
    // report 201 — a coincidental "success" that has nothing to do with the named
    // "shared-base" source actually holding anything for this organization.
    $bytes = random_bytes(256);
    $digest = Digest::of($bytes);
    $this->withServerVariables($this->publish)
        ->call('POST', "http://images.test/v2/app/blobs/uploads/?digest={$digest}", content: $bytes);

    $foreign = sharedDockerPackageIn($this->group, 'shared-base');
    seedBlobFor($foreign, $bytes);

    $this->withServerVariables($this->publish)
        ->post("http://images.test/v2/other/blobs/uploads/?mount={$digest}&from=shared-base")
        ->assertStatus(202)
        ->assertHeader('Docker-Upload-UUID');
});

it('refuses a write from a read-only token', function () {
    $this->withServerVariables($this->read)
        ->post('http://images.test/v2/app/blobs/uploads/')
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'DENIED');
});

it('refuses an anonymous write with 401 and the basic challenge', function () {
    $this->post('http://images.test/v2/app/blobs/uploads/')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="kontorfix"');
});

it('refuses an array digest on finish with the OCI error contract instead of a 500', function () {
    // PUT .../uploads/{id}?digest[]=x sends `digest` as an array. (string) $array used to
    // be an uncaught "Array to string conversion" that escaped the JSON error contract as
    // a bare 500 — requireDigest() now guards with is_string() before ever touching it,
    // mirroring the guard begin() already had for the same query parameter.
    $start = $this->withServerVariables($this->publish)->post('http://images.test/v2/app/blobs/uploads/');
    $location = $start->headers->get('Location');

    $this->withServerVariables($this->publish)
        ->call('PUT', $location.'?digest[]=x')
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'UNSUPPORTED');
});

it('omits the Range header on a fresh session rather than claiming a byte the client never sent', function () {
    // A fresh POST used to answer "Range: 0-0", which claims one byte is already on the
    // server when zero have arrived — a client resuming after an interrupted POST and
    // trusting that header would skip the first byte of its retry.
    $this->withServerVariables($this->publish)
        ->post('http://images.test/v2/app/blobs/uploads/')
        ->assertStatus(202)
        ->assertHeaderMissing('Range');
});
