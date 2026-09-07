<?php

use App\Enums\PackageType;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;

beforeEach(function () {
    $this->org = Organization::factory()->create([
        'enabled_registry_types' => ['composer', 'npm', 'python', 'docker'],
    ]);
    $this->group = Group::factory()->for($this->org)->create();
    Domain::create(['group_id' => $this->group->id, 'hostname' => 'images.test']);
});

it('challenges an anonymous client with Basic, which is what makes docker login send credentials', function () {
    $this->get('http://images.test/v2/')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="kontorfix"')
        ->assertHeader('Docker-Distribution-Api-Version', 'registry/2.0')
        ->assertJsonPath('errors.0.code', 'UNAUTHORIZED');
});

it('accepts the token as a basic-auth password', function () {
    $plain = tokenPlainTextFor($this->group);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('anything:'.$plain)])
        ->get('http://images.test/v2/')
        ->assertOk()
        ->assertHeader('Docker-Distribution-Api-Version', 'registry/2.0');
});

it('404s a host that is not a registry, so /v2/ does not advertise the instance', function () {
    $this->get('http://unknown.test/v2/')->assertNotFound();
});

it('404s a registry whose organization has not enabled the docker type', function () {
    $this->org->update(['enabled_registry_types' => ['composer']]);
    $plain = tokenPlainTextFor($this->group);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('x:'.$plain)])
        ->get('http://images.test/v2/')
        ->assertNotFound();
});

it('never registers /v2/ under the slug path, because a docker client cannot address one', function () {
    $plain = tokenPlainTextFor($this->group);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('x:'.$plain)])
        ->get(registryPath($this->group).'/v2/')
        ->assertNotFound();
});

it('answers the version check anonymously for a public registry, exactly as it already did for a manifest read', function () {
    // The scenario nothing tested before this fix: VersionController threw 401 for a null
    // token UNCONDITIONALLY, while ResolvesOciRepository::ociRepository() already
    // implemented and documented anonymous reads for a public group. A real client (real
    // docker/skopeo/crane, not curl aimed straight at a manifest URL) always asks GET
    // /v2/ FIRST and gives up on anything but 200 or a 401 it can retry with credentials
    // — so with the two disagreeing, a public Docker registry was unusable by any actual
    // client even though the manifest endpoint beneath it was already correctly public.
    $publicGroup = Group::factory()->for($this->org)->create(['public' => true]);
    Domain::create(['group_id' => $publicGroup->id, 'hostname' => 'public-images.test']);
    $pkg = Package::factory()->inOrgOf($publicGroup)->create(['type' => PackageType::Docker, 'name' => 'app']);
    $publicGroup->packages()->attach($pkg);

    // No Authorization header at all — genuinely anonymous, the shape a real client's
    // FIRST request against an unconfigured registry actually is.
    $this->get('http://public-images.test/v2/')
        ->assertOk()
        ->assertHeader('Docker-Distribution-Api-Version', 'registry/2.0');

    // The version check succeeding is the whole point only if what it gates was already
    // reachable too — proving both layers now agree, not merely that this one changed.
    // MANIFEST_UNKNOWN, not NAME_UNKNOWN: the repository itself resolved fine anonymously
    // (the property already in place before this fix), only the tag does not exist.
    $this->get('http://public-images.test/v2/app/manifests/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('errors.0.code', 'MANIFEST_UNKNOWN');
});

it('still challenges an anonymous client for a NON-public registry, even though the public case above now succeeds', function () {
    // The fix must not overcorrect into making every registry's version check anonymous —
    // only a genuinely public one. $this->group (beforeEach) is not public, so this is the
    // same assertion as "challenges an anonymous client with Basic" above, restated here to
    // sit next to the public case and make the contrast explicit.
    $this->get('http://images.test/v2/')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Basic realm="kontorfix"');
});
