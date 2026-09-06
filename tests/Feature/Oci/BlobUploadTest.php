<?php

use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Domain;
use App\Models\Group;
use App\Models\OciBlob;
use App\Models\Organization;
use App\Models\Package;
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

    $this->withServerVariables($this->read)
        ->head("http://images.test/v2/app/blobs/{$digest}")
        ->assertStatus(404);
});

it('mounts a blob from another repository of the same organization without transferring it', function () {
    $bytes = random_bytes(2048);
    $digest = Digest::of($bytes);
    $this->withServerVariables($this->publish)
        ->call('POST', "http://images.test/v2/app/blobs/uploads/?digest={$digest}", content: $bytes);

    $this->withServerVariables($this->publish)
        ->post("http://images.test/v2/other/blobs/uploads/?mount={$digest}&from=app")
        ->assertStatus(201)
        ->assertHeader('Docker-Content-Digest', $digest);
});

it('falls back to a normal upload when the mount source is in another organization', function () {
    // 202 rather than an error: that is the protocol's own miss path, and it leaks nothing
    // about whether the foreign blob exists.
    $digest = 'sha256:'.str_repeat('b', 64);

    $this->withServerVariables($this->publish)
        ->post("http://images.test/v2/app/blobs/uploads/?mount={$digest}&from=fremd")
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
