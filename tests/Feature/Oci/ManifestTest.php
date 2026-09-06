<?php

use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Domain;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciTag;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Oci\Digest;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

/**
 * A Basic-auth Authorization header, as a $server-vars array rather than a headers array.
 *
 * Mirrors basicAuthServerVars() in tests/Feature/Oci/BlobUploadTest.php: withHeaders()'s
 * `defaultHeaders` is only read by the convenience request methods
 * (transformHeadersToServerVars()), never by the raw call() this file needs for PUT/DELETE
 * with a genuine JSON body, so a token set via withHeaders() would silently vanish on
 * exactly the requests the manifest protocol is made of. withServerVariables() IS honoured
 * by call().
 *
 * @return array<string, string>
 */
function manifestAuthServerVars(Group $group, TokenAbility $ability): array
{
    $plain = tokenPlainTextFor($group, $ability);

    return ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('x:'.$plain)];
}

/**
 * PUTs a manifest with an explicit set of server variables (typically an auth header) and a
 * Content-Type, so callers never have to repeat the array-merge for CONTENT_TYPE.
 *
 * @param  array<string, string>  $serverVars
 * @return TestResponse<Response>
 */
function pushManifest(array $serverVars, string $reference, string $payload, string $mediaType = 'application/vnd.oci.image.manifest.v1+json'): TestResponse
{
    return test()->withServerVariables($serverVars + ['CONTENT_TYPE' => $mediaType])
        ->call('PUT', "http://images.test/v2/app/manifests/{$reference}", content: $payload);
}

beforeEach(function () {
    $this->org = Organization::factory()->create([
        'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
    ]);
    $this->group = Group::factory()->for($this->org)->create();
    Domain::create(['group_id' => $this->group->id, 'hostname' => 'images.test']);

    $app = Package::factory()->inOrgOf($this->group)->create(['type' => PackageType::Docker, 'name' => 'app']);
    $this->group->packages()->attach($app);

    $this->publish = manifestAuthServerVars($this->group, TokenAbility::Publish);
    $this->read = manifestAuthServerVars($this->group, TokenAbility::Read);
});

it('stores a manifest and points the tag at it', function () {
    $payload = '{"schemaVersion":2,"mediaType":"application/vnd.oci.image.manifest.v1+json","layers":[]}';
    $digest = Digest::of($payload);

    pushManifest($this->publish, '1.0', $payload)
        ->assertStatus(201)
        ->assertHeader('Docker-Content-Digest', $digest)
        ->assertHeader('Location', "/v2/app/manifests/{$digest}");

    $response = $this->withServerVariables($this->read)
        ->get('http://images.test/v2/app/manifests/1.0')
        ->assertOk()
        ->assertHeader('Docker-Content-Digest', $digest)
        ->assertHeader('Content-Type', 'application/vnd.oci.image.manifest.v1+json');

    expect($response->getContent())->toBe($payload);

    $manifest = OciManifest::where('digest', $digest)->sole();
    $tag = OciTag::where('name', '1.0')->sole();
    expect($tag->manifest_id)->toBe($manifest->id);
});

it('serves a manifest by digest as well as by tag', function () {
    $payload = '{"schemaVersion":2,"mediaType":"application/vnd.oci.image.manifest.v1+json"}';
    $digest = Digest::of($payload);

    pushManifest($this->publish, '1.0', $payload)->assertStatus(201);

    $byTag = $this->withServerVariables($this->read)->get('http://images.test/v2/app/manifests/1.0');
    $byDigest = $this->withServerVariables($this->read)->get("http://images.test/v2/app/manifests/{$digest}");

    $byTag->assertOk();
    $byDigest->assertOk()->assertHeader('Docker-Content-Digest', $digest);

    expect($byDigest->getContent())->toBe($payload)
        ->and($byDigest->getContent())->toBe($byTag->getContent());
});

it('stores and retrieves a manifest pushed directly by its own digest', function () {
    $payload = '{"schemaVersion":2,"pushed":"by-digest"}';
    $digest = Digest::of($payload);

    pushManifest($this->publish, $digest, $payload)
        ->assertStatus(201)
        ->assertHeader('Docker-Content-Digest', $digest);

    $this->withServerVariables($this->read)
        ->get("http://images.test/v2/app/manifests/{$digest}")
        ->assertOk()
        ->assertHeader('Docker-Content-Digest', $digest);
});

it('refuses a manifest pushed by a digest that does not match its bytes, and stores nothing', function () {
    // buildx writes every child manifest of a multi-arch image by digest rather than by
    // tag. Without this check, anything that alters the bytes in transit (a proxy, a retry
    // that re-serialises the body) would silently store the manifest under its REAL digest
    // while reporting success for the digest the client announced — an address the client
    // believes it just wrote to, that a GET can never find. Mirrors
    // BlobStore::finish()'s own "verify before promote" guarantee for layers.
    $payload = '{"schemaVersion":2,"pushed":"by-digest"}';
    $actualDigest = Digest::of($payload);
    $announcedDigest = 'sha256:'.str_repeat('d', 64);

    pushManifest($this->publish, $announcedDigest, $payload)
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'DIGEST_INVALID');

    expect(OciManifest::where('digest', $announcedDigest)->count())->toBe(0)
        ->and(OciManifest::where('digest', $actualDigest)->count())->toBe(0);

    $this->withServerVariables($this->read)
        ->get("http://images.test/v2/app/manifests/{$announcedDigest}")
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'MANIFEST_UNKNOWN');
});

it('returns the manifest bytes verbatim', function () {
    // The digest is the hash of these exact bytes, whitespace and all. Round-tripping
    // through json_decode/json_encode would reformat this (collapsing the newlines and
    // indentation) and produce a DIFFERENT digest — breaking every reference to this
    // manifest, including the ones cosign writes.
    $payload = "{\n  \"schemaVersion\": 2,\n  \"mediaType\": \"application/vnd.oci.image.manifest.v1+json\"\n}";

    pushManifest($this->publish, 'verbatim', $payload)->assertStatus(201);

    $response = $this->withServerVariables($this->read)
        ->get('http://images.test/v2/app/manifests/verbatim')
        ->assertOk();

    expect($response->getContent())->toBe($payload);

    // Every other fixture in this file happens to end in "}" — a mutant that trims a
    // single trailing byte off the stored payload (e.g. `rtrim($payload, "\n")`) would
    // slip past every one of them unnoticed. `oras push`, and `docker manifest push` from
    // a file, both routinely send a manifest with a trailing newline, so that byte has to
    // survive the round trip too.
    $trailingNewline = $payload."\n";

    pushManifest($this->publish, 'verbatim-newline', $trailingNewline)->assertStatus(201);

    $withNewline = $this->withServerVariables($this->read)
        ->get('http://images.test/v2/app/manifests/verbatim-newline')
        ->assertOk();

    expect($withNewline->getContent())->toBe($trailingNewline);
});

it('accepts an image index, because buildx pushes one for provenance and sbom', function () {
    $payload = json_encode([
        'schemaVersion' => 2,
        'mediaType' => 'application/vnd.oci.image.index.v1+json',
        'manifests' => [
            ['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => 'sha256:'.str_repeat('a', 64), 'size' => 100],
            [
                'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
                'digest' => 'sha256:'.str_repeat('b', 64),
                'size' => 200,
                'annotations' => ['vnd.docker.reference.type' => 'attestation-manifest'],
            ],
        ],
    ]);
    $digest = Digest::of($payload);

    pushManifest($this->publish, 'index-tag', $payload, 'application/vnd.oci.image.index.v1+json')
        ->assertStatus(201)
        ->assertHeader('Docker-Content-Digest', $digest);

    $response = $this->withServerVariables($this->read)
        ->get('http://images.test/v2/app/manifests/index-tag')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.oci.image.index.v1+json');

    expect($response->getContent())->toBe($payload);
});

it('accepts a docker manifest list, the legacy equivalent of an image index', function () {
    $payload = '{"schemaVersion":2,"mediaType":"application/vnd.docker.distribution.manifest.list.v2+json","manifests":[]}';

    pushManifest($this->publish, 'list-tag', $payload, 'application/vnd.docker.distribution.manifest.list.v2+json')
        ->assertStatus(201);

    $this->withServerVariables($this->read)
        ->get('http://images.test/v2/app/manifests/list-tag')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.docker.distribution.manifest.list.v2+json');
});

it('lists tags', function () {
    pushManifest($this->publish, '1.0', '{"schemaVersion":2,"a":1}')->assertStatus(201);
    pushManifest($this->publish, '2.0', '{"schemaVersion":2,"a":2}')->assertStatus(201);
    pushManifest($this->publish, 'latest', '{"schemaVersion":2,"a":3}')->assertStatus(201);

    // assertExactJson rather than assertJson: assertJson is a subset check, so it would
    // stay green even if tags() lost its package_id filter and appended foreign tags after
    // these three — it would still find the three expected entries and never notice the
    // extras.
    $this->withServerVariables($this->read)
        ->get('http://images.test/v2/app/tags/list')
        ->assertOk()
        ->assertExactJson(['name' => 'app', 'tags' => ['1.0', '2.0', 'latest']]);
});

it('moves a tag when it is pushed again', function () {
    $first = '{"schemaVersion":2,"build":1}';
    $second = '{"schemaVersion":2,"build":2}';
    $firstDigest = Digest::of($first);
    $secondDigest = Digest::of($second);

    pushManifest($this->publish, 'latest', $first)->assertStatus(201);
    pushManifest($this->publish, 'latest', $second)->assertStatus(201);

    $package = Package::where('name', 'app')->sole();
    expect(OciTag::where('package_id', $package->id)->where('name', 'latest')->count())->toBe(1);

    $tag = OciTag::where('name', 'latest')->sole();
    expect($tag->manifest->digest)->toBe($secondDigest);

    // The old manifest row is untouched (still resolvable by its own digest) — moving a
    // tag does not delete the content it used to point at.
    $this->withServerVariables($this->read)
        ->get("http://images.test/v2/app/manifests/{$firstDigest}")
        ->assertOk();
});

it('deletes a manifest and the tags pointing at it', function () {
    $payload = '{"schemaVersion":2,"gone":true}';
    $digest = Digest::of($payload);
    pushManifest($this->publish, 'to-delete', $payload)->assertStatus(201);

    $this->withServerVariables($this->publish)
        ->call('DELETE', "http://images.test/v2/app/manifests/{$digest}")
        ->assertStatus(202);

    expect(OciManifest::where('digest', $digest)->count())->toBe(0)
        ->and(OciTag::where('name', 'to-delete')->count())->toBe(0);

    $this->withServerVariables($this->read)
        ->get("http://images.test/v2/app/manifests/{$digest}")
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'MANIFEST_UNKNOWN');
});

it('404s an unknown reference with MANIFEST_UNKNOWN', function () {
    $this->withServerVariables($this->read)
        ->get('http://images.test/v2/app/manifests/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'MANIFEST_UNKNOWN');

    $this->withServerVariables($this->read)
        ->get('http://images.test/v2/app/manifests/sha256:'.str_repeat('c', 64))
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'MANIFEST_UNKNOWN');
});

it('refuses a manifest write from a read-only token', function () {
    pushManifest($this->read, '1.0', '{"schemaVersion":2}')
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'DENIED');
});

it('refuses an anonymous manifest write with 401 and the basic challenge', function () {
    pushManifest([], '1.0', '{"schemaVersion":2}')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="kontorfix"');
});

it('does not leak another repository\'s manifest under the same tag name', function () {
    $other = Package::factory()->inOrgOf($this->group)->create(['type' => PackageType::Docker, 'name' => 'other']);
    $this->group->packages()->attach($other);

    $payload = '{"schemaVersion":2,"owner":"app"}';
    pushManifest($this->publish, 'shared-name', $payload)->assertStatus(201);

    $this->withServerVariables($this->read)
        ->get('http://images.test/v2/other/manifests/shared-name')
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'MANIFEST_UNKNOWN');
});
