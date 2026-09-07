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
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

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
    $group = Group::factory()->for(Organization::factory())->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    $manifest = OciManifest::factory()->for($pkg, 'package')->create(['size' => 198 * 1024 * 1024]);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifest->id, 'name' => '1.4.0']);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifest->id, 'name' => 'latest']);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/DockerTags')
            // The whole point of this test: a manifest two tags point at is ONE manifest.
            // If the composition summed each tag's manifest in full, this would read
            // 396 MiB (198 * 2) instead of the 198 MiB actually on disk.
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
    $group = Group::factory()->for(Organization::factory())->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'docker', 'name' => 'meinapp']);
    $group->packages()->attach($pkg);

    $manifest = OciManifest::factory()->for($pkg, 'package')->create(['size' => 17 * 1024 * 1024]);
    OciTag::factory()->create(['package_id' => $pkg->id, 'manifest_id' => $manifest->id, 'name' => 'nightly']);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/packages/DockerTags')
            ->where('stats.occupied_bytes', 17 * 1024 * 1024)
            ->where('stats.shared_bytes', 0)
            ->where('tags.0.shared', false)
            ->where('tags.0.size_bytes', 17 * 1024 * 1024));
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
