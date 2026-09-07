<?php

use App\Enums\UserRole;
use App\Models\Domain;
use App\Models\Group;
use App\Models\OciBlob;
use App\Models\OciManifest;
use App\Models\OciTag;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

/**
 * A realistic manifest: real OciBlob rows (a config blob plus one layer), referenced by
 * digest from the manifest's own payload — the shape PackageController::showDocker()
 * actually sums (see reachableBlobDigests()), not a number forced directly onto the
 * manifest row. An earlier version of these tests did exactly that
 * (`OciManifest::factory()->create(['size' => 198 * 1024 * 1024])` on a 78-byte payload) —
 * the assertion passed, but it was checking a fabricated number, not the summation this
 * page is actually supposed to perform; mutating the summation logic to sum manifest
 * DOCUMENT size again (the bug this whole fix closes) would have left it green.
 */
function dockerManifestWithBlobs(Package $pkg, int $totalBytes): OciManifest
{
    $configBytes = 2048;
    $layerBytes = $totalBytes - $configBytes;
    $configDigest = 'sha256:'.hash('sha256', 'config-'.$pkg->id.'-'.$totalBytes);
    $layerDigest = 'sha256:'.hash('sha256', 'layer-'.$pkg->id.'-'.$totalBytes);

    OciBlob::factory()->create(['organization_id' => $pkg->organization_id, 'digest' => $configDigest, 'size' => $configBytes]);
    OciBlob::factory()->create(['organization_id' => $pkg->organization_id, 'digest' => $layerDigest, 'size' => $layerBytes]);

    $payload = json_encode([
        'schemaVersion' => 2,
        'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
        'config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json', 'digest' => $configDigest, 'size' => $configBytes],
        'layers' => [['mediaType' => 'application/vnd.oci.image.layer.v1.tar+gzip', 'digest' => $layerDigest, 'size' => $layerBytes]],
    ]);

    return OciManifest::factory()->for($pkg, 'package')->create([
        'media_type' => 'application/vnd.oci.image.manifest.v1+json',
        'payload' => $payload,
        'size' => strlen($payload),
    ]);
}

// --- Plate 1: the registry-level Einrichtung tab (RegistrySetup.vue / SetupSnippetBuilder) ---

it('shows the docker empty state on the registry setup page when the registry has no domain', function () {
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'dritte-b']))->create(['slug' => 'intern']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    $this->actingAs($this->admin)->get(route('admin.groups.show', $group->id))
        ->assertOk()
        // Asserting the PROP, not rendered HTML: null is the fact itself (no domain, so no
        // host a Docker client could use), not a placeholder string to sniff for.
        ->assertInertia(fn ($page) => $page->component('admin/groups/Show')
            ->where('setup.dockerHost', null)
            ->where('setup.dockerPath', '/r/dritte-b/intern'));
});

it('shows the docker host in the registry setup page once a domain is attached', function () {
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'dritte-b']))->create(['slug' => 'intern']);
    Domain::factory()->for($group)->create(['hostname' => 'images.3b.de']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    $this->actingAs($this->admin)->get(route('admin.groups.show', $group->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/groups/Show')
            ->where('setup.dockerHost', 'images.3b.de')
            ->where('setup.dockerExample', 'meinapp'));
});

// --- Plate 2: the package-level tag table (DockerTags.vue / PackageController::showDocker) ---

it('counts a manifest shared by two tags once in the occupied total, and reports no own bytes for the shared tag', function () {
    Storage::fake('artifacts');

    $group = Group::factory()->for(Organization::factory())->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    $manifest = dockerManifestWithBlobs($pkg, 198 * 1024 * 1024);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifest->id, 'name' => '1.4.0']);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifest->id, 'name' => 'latest']);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/DockerTags')
            // The whole point of this test: a manifest two tags point at is ONE manifest.
            // If the composition summed each tag's manifest in full, this would read
            // 396 MiB (198 * 2) instead of the 198 MiB the referenced blobs actually total.
            ->where('stats.occupied_bytes', 198 * 1024 * 1024)
            ->where('stats.shared_bytes', 198 * 1024 * 1024)
            ->where('stats.tag_count', 2)
            ->has('tags', 2)
            ->where('tags.0.shared', true)
            ->where('tags.0.size_bytes', null)
            ->where('tags.1.shared', true)
            ->where('tags.1.size_bytes', null));
});

it('gives an unshared tag its own bytes, and leaves it out of the shared total', function () {
    Storage::fake('artifacts');

    $group = Group::factory()->for(Organization::factory())->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    $manifest = dockerManifestWithBlobs($pkg, 17 * 1024 * 1024);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifest->id, 'name' => 'nightly']);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/DockerTags')
            ->where('stats.occupied_bytes', 17 * 1024 * 1024)
            ->where('stats.shared_bytes', 0)
            ->where('tags.0.shared', false)
            ->where('tags.0.size_bytes', 17 * 1024 * 1024));
});

it('counts a base layer shared across two DIFFERENT (non-aliased) tags once in the occupied total', function () {
    // The dedup Important 1 also asks for: not merely "two tags naming the same
    // manifest" (the case above), but two DIFFERENT manifests — genuinely different
    // images, different tags, never aliased — that happen to reference the SAME layer
    // blob (the ordinary shape of two builds FROM the same base image). Neither manifest
    // is "shared" in the tag-alias sense (each has exactly one tag), so neither tag's
    // own `size_bytes` should be null — but the repository TOTAL must still count the
    // shared base layer once, not twice, because the real disk only holds one copy of it.
    Storage::fake('artifacts');

    $group = Group::factory()->for(Organization::factory())->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    $baseDigest = 'sha256:'.hash('sha256', 'shared-base-layer');
    $baseBytes = 50 * 1024 * 1024;
    OciBlob::factory()->create(['organization_id' => $pkg->organization_id, 'digest' => $baseDigest, 'size' => $baseBytes]);

    $ownBytesA = 4 * 1024 * 1024;
    $ownDigestA = 'sha256:'.hash('sha256', 'own-layer-a');
    OciBlob::factory()->create(['organization_id' => $pkg->organization_id, 'digest' => $ownDigestA, 'size' => $ownBytesA]);
    $payloadA = json_encode([
        'schemaVersion' => 2,
        'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
        'layers' => [
            ['mediaType' => 'application/vnd.oci.image.layer.v1.tar+gzip', 'digest' => $baseDigest, 'size' => $baseBytes],
            ['mediaType' => 'application/vnd.oci.image.layer.v1.tar+gzip', 'digest' => $ownDigestA, 'size' => $ownBytesA],
        ],
    ]);
    $manifestA = OciManifest::factory()->for($pkg, 'package')->create(['media_type' => 'application/vnd.oci.image.manifest.v1+json', 'payload' => $payloadA, 'size' => strlen($payloadA)]);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifestA->id, 'name' => 'app-a']);

    $ownBytesB = 6 * 1024 * 1024;
    $ownDigestB = 'sha256:'.hash('sha256', 'own-layer-b');
    OciBlob::factory()->create(['organization_id' => $pkg->organization_id, 'digest' => $ownDigestB, 'size' => $ownBytesB]);
    $payloadB = json_encode([
        'schemaVersion' => 2,
        'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
        'layers' => [
            ['mediaType' => 'application/vnd.oci.image.layer.v1.tar+gzip', 'digest' => $baseDigest, 'size' => $baseBytes],
            ['mediaType' => 'application/vnd.oci.image.layer.v1.tar+gzip', 'digest' => $ownDigestB, 'size' => $ownBytesB],
        ],
    ]);
    $manifestB = OciManifest::factory()->for($pkg, 'package')->create(['media_type' => 'application/vnd.oci.image.manifest.v1+json', 'payload' => $payloadB, 'size' => strlen($payloadB)]);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifestB->id, 'name' => 'app-b']);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/DockerTags')
            // Each tag's OWN size still counts the base layer in full — it genuinely
            // needs those bytes to be pulled — but the repository total dedupes it.
            ->where('stats.occupied_bytes', $baseBytes + $ownBytesA + $ownBytesB)
            ->where('stats.shared_bytes', 0)
            ->where('tags.0.shared', false)
            ->where('tags.1.shared', false));
});

it('shows no docker access on the package page when its registries have no domain', function () {
    $group = Group::factory()->for(Organization::factory())->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/DockerTags')
            ->where('access.host', null)
            ->where('access.registry_path', '/r/'.$group->organization->slug.'/'.$group->slug));
});

it('shows the real docker host on the package page once its registry has a domain', function () {
    // The mirror of the case above — only the no-domain branch was covered before, so a
    // regression in $dockerGroup's selection (picking a domain-less group, or always
    // returning null even when one qualifies) would have gone uncaught here even though
    // the equivalent registry-level case in SetupSnippetBuilderTest has both branches.
    $group = Group::factory()->for(Organization::factory()->create(['slug' => 'dritte-b']))->create(['slug' => 'intern']);
    Domain::factory()->for($group)->create(['hostname' => 'images.3b.de']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/DockerTags')
            ->where('access.host', 'images.3b.de')
            ->where('access.registry_path', '/r/dritte-b/intern'));
});

// --- Platform, one of the five columns the brief names — none of the composition tests
// above touch it at all: swapping os/architecture, or breaking the multi-arch summary,
// left every test above green. ---

it('reads the config blob a constant number of times, not once per tag aliasing the manifest', function () {
    // Correctness alone does not prove this: platformFor() reading the config blob once
    // per TAG instead of once per unique manifest produces the SAME `platform` answer for
    // every tag either way (Task 8's own Important 1 finding — a report claimed the fix
    // on the strength of the size dedup, which does not touch this loop at all). Counting
    // queries is the only way to catch it going back to O(tags): with two tags pointing at
    // one manifest, the number of queries this page issues against oci_blobs for the
    // config digest must be the SAME as it is for one tag, not double.
    Storage::fake('artifacts');

    $group = Group::factory()->for(Organization::factory())->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    $configDigest = 'sha256:'.hash('sha256', 'shared-config');
    $config = json_encode(['os' => 'linux', 'architecture' => 'amd64']);
    $blob = OciBlob::factory()->create(['organization_id' => $pkg->organization_id, 'digest' => $configDigest, 'size' => strlen($config)]);
    Storage::disk('artifacts')->put($blob->path, $config);

    $manifestPayload = json_encode([
        'schemaVersion' => 2,
        'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
        'config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json', 'digest' => $configDigest, 'size' => strlen($config)],
    ]);
    $manifest = OciManifest::factory()->for($pkg, 'package')->create([
        'media_type' => 'application/vnd.oci.image.manifest.v1+json',
        'payload' => $manifestPayload,
        'size' => strlen($manifestPayload),
    ]);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifest->id, 'name' => 'v1.0.0']);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifest->id, 'name' => 'latest']);

    $queriesForConfigDigest = 0;
    DB::listen(function ($query) use (&$queriesForConfigDigest, $configDigest) {
        if (str_contains($query->sql, 'oci_blobs') && in_array($configDigest, $query->bindings, true)) {
            $queriesForConfigDigest++;
        }
    });

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/DockerTags')
            ->where('tags.0.platform', 'linux/amd64')
            ->where('tags.1.platform', 'linux/amd64'));

    // Two independent lookups touch oci_blobs for this digest — platformFor()'s own read
    // and the size composition's reachableBlobDigests()/sizeOf() read — each memoized
    // separately, so the constant is 2 rather than 1. What this test actually pins is
    // that it stays 2 with TWO tags, not 4: the number scales with distinct manifests,
    // never with how many tag names point at them.
    expect($queriesForConfigDigest)->toBe(2);
});

it('resolves a single-image manifest platform from its config blob', function () {
    Storage::fake('artifacts');

    $group = Group::factory()->for(Organization::factory())->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    // The OCI Image Manifest itself carries no platform field — it lives in the config
    // blob the manifest points at (Image Configuration spec's os/architecture).
    $configDigest = 'sha256:'.hash('sha256', 'config-bytes');
    $config = json_encode(['os' => 'linux', 'architecture' => 'amd64']);
    $blob = OciBlob::factory()->create([
        'organization_id' => $pkg->organization_id,
        'digest' => $configDigest,
        'size' => strlen($config),
    ]);
    Storage::disk('artifacts')->put($blob->path, $config);

    $manifestPayload = json_encode([
        'schemaVersion' => 2,
        'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
        'config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json', 'digest' => $configDigest, 'size' => strlen($config)],
    ]);
    $manifest = OciManifest::factory()->for($pkg, 'package')->create([
        'media_type' => 'application/vnd.oci.image.manifest.v1+json',
        'payload' => $manifestPayload,
        'size' => strlen($manifestPayload),
    ]);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifest->id, 'name' => 'v1.0.0']);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/DockerTags')
            ->where('tags.0.platform', 'linux/amd64'));
});

it('summarises a multi-arch index as multi-arch, reading no blob for it', function () {
    Storage::fake('artifacts');

    $group = Group::factory()->for(Organization::factory())->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    // An index lists one descriptor per platform directly in its own payload — no config
    // blob read needed, and none exists on the fake disk for either child digest here.
    $indexPayload = json_encode([
        'schemaVersion' => 2,
        'mediaType' => 'application/vnd.oci.image.index.v1+json',
        'manifests' => [
            ['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => 'sha256:'.hash('sha256', 'amd64'), 'platform' => ['os' => 'linux', 'architecture' => 'amd64']],
            ['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => 'sha256:'.hash('sha256', 'arm64'), 'platform' => ['os' => 'linux', 'architecture' => 'arm64']],
        ],
    ]);
    $manifest = OciManifest::factory()->for($pkg, 'package')->create([
        'media_type' => 'application/vnd.oci.image.index.v1+json',
        'payload' => $indexPayload,
        'size' => strlen($indexPayload),
    ]);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifest->id, 'name' => 'latest']);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/DockerTags')
            ->where('tags.0.platform', 'multi-arch'));
});

it('names a single-platform index by its actual os/architecture, not a swapped one', function () {
    // The multi-arch test above always collapses two-or-more distinct platforms to the
    // literal string 'multi-arch' — a mutant that swaps `$os`/`$architecture` in
    // platformFor()'s "{$os}/{$arch}" concatenation still produces two distinct
    // (swapped) strings, still collapses to 'multi-arch', and the assertion never
    // notices. An index naming exactly ONE platform takes the OTHER branch
    // ($platforms->count() === 1) and returns that concatenated string directly — this is
    // the only shape that can catch the swap.
    Storage::fake('artifacts');

    $group = Group::factory()->for(Organization::factory())->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    $indexPayload = json_encode([
        'schemaVersion' => 2,
        'mediaType' => 'application/vnd.oci.image.index.v1+json',
        'manifests' => [
            ['mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'digest' => 'sha256:'.hash('sha256', 'single-arm64'), 'platform' => ['os' => 'linux', 'architecture' => 'arm64']],
        ],
    ]);
    $manifest = OciManifest::factory()->for($pkg, 'package')->create([
        'media_type' => 'application/vnd.oci.image.index.v1+json',
        'payload' => $indexPayload,
        'size' => strlen($indexPayload),
    ]);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifest->id, 'name' => 'arm-only']);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/DockerTags')
            ->where('tags.0.platform', 'linux/arm64'));
});

it('reports no platform, rather than guessing, when the config blob is missing', function () {
    Storage::fake('artifacts');

    $group = Group::factory()->for(Organization::factory())->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    // Points at a config digest with no corresponding OciBlob row at all — a database
    // restore older than the artifacts volume, or simply never pushed in this test.
    $manifestPayload = json_encode([
        'schemaVersion' => 2,
        'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
        'config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json', 'digest' => 'sha256:'.hash('sha256', 'missing'), 'size' => 2],
    ]);
    $manifest = OciManifest::factory()->for($pkg, 'package')->create([
        'media_type' => 'application/vnd.oci.image.manifest.v1+json',
        'payload' => $manifestPayload,
        'size' => strlen($manifestPayload),
    ]);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifest->id, 'name' => 'v1.0.0']);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/DockerTags')
            ->where('tags.0.platform', null));
});

it('is operator-gated, the same as every other package type', function () {
    $pkg = Package::factory()->create(['type' => 'docker']);
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    $this->actingAs($custAdmin)->get("/admin/packages/{$pkg->id}")->assertForbidden();
});
