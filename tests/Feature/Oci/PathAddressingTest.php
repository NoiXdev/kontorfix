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
use Illuminate\Support\Facades\Storage;

/**
 * A registry addressed by PATH NAMESPACE on the instance's own host —
 * `<instance>/v2/<org>/<registry>/<repository>` — beside the existing custom-domain
 * addressing, which this file also re-checks so the two cannot drift apart.
 *
 * Every request here is aimed at the instance's own host and there is deliberately NO row
 * in `domains`: that absence is the whole condition ResolveOciContext decides on, so a
 * stray `domains` row anywhere in this file would silently turn every case below back
 * into the domain-mode coverage the rest of tests/Feature/Oci already provides.
 */

/**
 * The instance's own host, read from `config('app.url')` rather than written out. A test
 * that addresses the instance through this keeps asserting what it means — "the host this
 * application answers to, which is not a registry domain" — instead of pinning whatever
 * hostname phpunit.xml happens to set.
 */
function instanceAddress(string $path): string
{
    return rtrim((string) config('app.url'), '/').$path;
}

/**
 * A Basic-auth Authorization header, as a $server-vars array rather than a headers array.
 *
 * Same reason as basicAuthServerVars() in tests/Feature/Oci/BlobUploadTest.php:
 * withHeaders()'s `defaultHeaders` is only read by the convenience request methods, never
 * by the raw call() the push case below needs for POST/PUT with a genuine body, so a token
 * set via withHeaders() would silently vanish on exactly those requests.
 *
 * @return array<string, string>
 */
function pathAuth(Group $group, TokenAbility $ability = TokenAbility::Read): array
{
    return ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('x:'.tokenPlainTextFor($group, $ability))];
}

/**
 * A manifest and a tag pointing at it, written straight to the models rather than pushed
 * through the protocol: the read cases below are about which repository the URL resolves
 * to, and routing a push through the same address first would make a failing read
 * ambiguous between the two halves. The push case exercises the write path on its own.
 */
function seedManifest(Package $package, string $tag, string $payload): string
{
    $digest = Digest::of($payload);

    $manifest = OciManifest::create([
        'package_id' => $package->id,
        'digest' => $digest,
        'media_type' => 'application/vnd.oci.image.manifest.v1+json',
        'payload' => $payload,
        'size' => strlen($payload),
    ]);

    OciTag::create(['package_id' => $package->id, 'name' => $tag, 'manifest_id' => $manifest->id]);

    return $digest;
}

beforeEach(function () {
    Storage::fake('artifacts');

    $this->org = Organization::factory()->create([
        'slug' => '3b',
        'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
    ]);
    $this->group = Group::factory()->for($this->org)->create(['slug' => 'intern']);

    // NOT $this->app: Illuminate\Foundation\Testing\TestCase declares its own $app (the
    // application container), and assigning the repository there would replace it.
    $this->repo = Package::factory()->inOrgOf($this->group)->create([
        'type' => PackageType::Docker,
        'name' => 'meinapp',
    ]);
    $this->group->packages()->attach($this->repo);

    $this->read = pathAuth($this->group);
    $this->publish = pathAuth($this->group, TokenAbility::Publish);

    $this->payload = '{"schemaVersion":2,"mediaType":"application/vnd.oci.image.manifest.v1+json","layers":[]}';
});

it('serves a manifest addressed as /v2/<org>/<registry>/<repository> on the instance host', function () {
    $digest = seedManifest($this->repo, '1.0', $this->payload);

    $response = $this->withServerVariables($this->read)
        ->get(instanceAddress('/v2/3b/intern/meinapp/manifests/1.0'))
        ->assertOk()
        ->assertHeader('Docker-Content-Digest', $digest);

    expect($response->getContent())->toBe($this->payload);
});

it('accepts a push through the path address, blob and manifest alike', function () {
    // The write path, end to end through the same address: `ociWritableRepository()` runs
    // against the group the resolver produced, so a push proves the rewritten `name`
    // reaches the controller as the bare repository name — a leftover `3b/intern/` prefix
    // would be NAME_UNKNOWN here, not a 201.
    $bytes = 'ein-layer';
    $blobDigest = Digest::of($bytes);

    $this->withServerVariables($this->publish)
        ->call('POST', instanceAddress("/v2/3b/intern/meinapp/blobs/uploads/?digest={$blobDigest}"), content: $bytes)
        ->assertStatus(201)
        ->assertHeader('Docker-Content-Digest', $blobDigest)
        ->assertHeader('Location', "/v2/3b/intern/meinapp/blobs/{$blobDigest}");

    $manifestDigest = Digest::of($this->payload);

    $this->withServerVariables($this->publish + ['CONTENT_TYPE' => 'application/vnd.oci.image.manifest.v1+json'])
        ->call('PUT', instanceAddress('/v2/3b/intern/meinapp/manifests/1.0'), content: $this->payload)
        ->assertStatus(201)
        ->assertHeader('Docker-Content-Digest', $manifestDigest)
        ->assertHeader('Location', "/v2/3b/intern/meinapp/manifests/{$manifestDigest}");

    // And the tag the push created is readable back through the same address.
    $this->withServerVariables($this->read)
        ->get(instanceAddress('/v2/3b/intern/meinapp/manifests/1.0'))
        ->assertOk()
        ->assertHeader('Docker-Content-Digest', $manifestDigest);
});

it('splits exactly two segments, so a repository whose own name contains a slash resolves', function () {
    // The case that distinguishes "take the first two segments" from "take everything up
    // to the last one": `team/app` is a legal OCI repository name, so `3b/intern/team/app`
    // has to mean org `3b`, registry `intern`, repository `team/app` — not registry
    // `intern/team` or repository `app`.
    $teamApp = Package::factory()->inOrgOf($this->group)->create([
        'type' => PackageType::Docker,
        'name' => 'team/app',
    ]);
    $this->group->packages()->attach($teamApp);

    $digest = seedManifest($teamApp, '1.0', $this->payload);

    $this->withServerVariables($this->read)
        ->get(instanceAddress('/v2/3b/intern/team/app/manifests/1.0'))
        ->assertOk()
        ->assertHeader('Docker-Content-Digest', $digest);
});

it('answers NAME_UNKNOWN for a repository that does not exist in the addressed registry', function () {
    // The registry resolved; only the repository is absent — so this is the protocol's own
    // error envelope, exactly as it is in domain mode, and not a routing miss.
    $this->withServerVariables($this->read)
        ->get(instanceAddress('/v2/3b/intern/nichtda/manifests/1.0'))
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');
});

it('answers a plain 404 for an unknown registry slug, with no OCI error body', function () {
    // Nothing resolved, so nothing may be reported about it: an `errors[]` envelope here
    // would confirm that the organization `3b` exists to a caller who guessed at registry
    // names. The same distinction ResolvesOciRepository draws between a malformed name and
    // an absent one.
    $response = $this->withServerVariables($this->read)
        ->get(instanceAddress('/v2/3b/falsch/meinapp/manifests/1.0'))
        ->assertNotFound();

    expect($response->headers->get('Content-Type'))->toStartWith('text/html');
});

it('answers a plain 404 when fewer than three segments were named on a non-domain host', function () {
    // `meinapp` alone names no organization and no registry, so the caller named nothing
    // that could exist — a routing-level miss, not a repository this registry does not have.
    $response = $this->withServerVariables($this->read)
        ->get(instanceAddress('/v2/meinapp/manifests/1.0'))
        ->assertNotFound();

    expect($response->headers->get('Content-Type'))->toStartWith('text/html');
});

it('refuses a foreign organization token exactly as domain mode does, and returns no bytes', function () {
    // Tenancy is unchanged by construction — both modes resolve to the same Group and every
    // existing check runs on it untouched — and this says so out loud, by asking the same
    // question through both addresses and comparing the answers rather than asserting one
    // of them in isolation.
    $digest = seedManifest($this->repo, '1.0', $this->payload);
    Domain::create(['group_id' => $this->group->id, 'hostname' => 'images.test']);

    $foreignOrg = Organization::factory()->create(['enabled_registry_types' => ['docker']]);
    $foreignGroup = Group::factory()->for($foreignOrg)->create();
    $foreign = pathAuth($foreignGroup);

    $viaPath = $this->withServerVariables($foreign)
        ->get(instanceAddress('/v2/3b/intern/meinapp/manifests/1.0'));

    $viaDomain = $this->withServerVariables($foreign)
        ->get('http://images.test/v2/meinapp/manifests/1.0');

    $viaPath->assertStatus(404)->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');
    $viaDomain->assertStatus(404)->assertJsonPath('errors.0.code', 'NAME_UNKNOWN');

    expect($viaPath->getStatusCode())->toBe($viaDomain->getStatusCode())
        ->and($viaPath->getContent())->not->toContain($digest)
        ->and($viaPath->getContent())->not->toContain('schemaVersion');
});

it('leaves a domain-addressed repository name whole and never splits it', function () {
    Domain::create(['group_id' => $this->group->id, 'hostname' => 'images.test']);

    $digest = seedManifest($this->repo, '1.0', $this->payload);

    $this->withServerVariables($this->read)
        ->get('http://images.test/v2/meinapp/manifests/1.0')
        ->assertOk()
        ->assertHeader('Docker-Content-Digest', $digest);
});

it('hands back an upload session at an address the caller can actually follow', function () {
    // The defect a real `docker push` found and no feature test would have: `{name}` reaches
    // the controllers as the BARE repository name (that is the whole point of the rewrite),
    // so a `Location` built from it pointed at `/v2/meinapp/blobs/uploads/<id>` — one
    // segment on this host, which is a 404. A client follows an upload Location without
    // asking, so the push died on the very next request with
    // `unexpected status from PUT request … 404 Not Found`.
    //
    // Asserted twice over: the Location is in the caller's own address space, AND this
    // application actually routes it — a string assertion alone would have been satisfied
    // by any prefix that merely looked right.
    $begin = $this->withServerVariables($this->publish)
        ->call('POST', instanceAddress('/v2/3b/intern/meinapp/blobs/uploads/'))
        ->assertStatus(202);

    $location = (string) $begin->headers->get('Location');

    expect($location)->toStartWith('/v2/3b/intern/meinapp/blobs/uploads/');

    $bytes = 'ein-chunk';

    $this->withServerVariables($this->publish)
        ->call('PATCH', instanceAddress($location), content: $bytes)
        ->assertStatus(202)
        ->assertHeader('Location', $location);

    $digest = Digest::of($bytes);

    $this->withServerVariables($this->publish)
        ->call('PUT', instanceAddress($location."?digest={$digest}"))
        ->assertStatus(201)
        ->assertHeader('Location', "/v2/3b/intern/meinapp/blobs/{$digest}");
});

it('keeps a domain-addressed Location bare, exactly as it was before path addressing', function () {
    Domain::create(['group_id' => $this->group->id, 'hostname' => 'images.test']);

    $bytes = 'ein-layer';
    $digest = Digest::of($bytes);

    $this->withServerVariables($this->publish)
        ->call('POST', "http://images.test/v2/meinapp/blobs/uploads/?digest={$digest}", content: $bytes)
        ->assertStatus(201)
        ->assertHeader('Location', "/v2/meinapp/blobs/{$digest}");
});
