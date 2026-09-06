<?php

use App\Enums\PackageType;
use App\Models\OciBlob;
use App\Models\OciManifest;
use App\Models\OciTag;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Database\QueryException;

it('deduplicates a blob within an organization but not across organizations', function () {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $digest = 'sha256:'.str_repeat('a', 64);

    OciBlob::create(['organization_id' => $a->id, 'digest' => $digest, 'size' => 10, 'path' => 'p/a']);

    // The same bytes in another tenant are a separate row. A global unique index would make
    // a blob-existence check answer differently depending on what OTHER customers hold.
    OciBlob::create(['organization_id' => $b->id, 'digest' => $digest, 'size' => 10, 'path' => 'p/b']);

    expect(OciBlob::where('digest', $digest)->count())->toBe(2);

    expect(fn () => OciBlob::create(['organization_id' => $a->id, 'digest' => $digest, 'size' => 10, 'path' => 'p/c']))
        ->toThrow(QueryException::class);
});

it('points a tag at a manifest and reads it back', function () {
    $package = Package::factory()->create(['type' => PackageType::Docker]);
    $manifest = OciManifest::create([
        'package_id' => $package->id,
        'digest' => 'sha256:'.str_repeat('b', 64),
        'media_type' => 'application/vnd.oci.image.manifest.v1+json',
        'payload' => '{"schemaVersion":2}',
        'size' => 19,
    ]);

    $tag = OciTag::create([
        'package_id' => $package->id,
        'name' => '1.4.0',
        'manifest_id' => $manifest->id,
    ]);

    expect($tag->manifest->is($manifest))->toBeTrue()
        ->and($manifest->tags()->pluck('name')->all())->toBe(['1.4.0']);
});

it('keeps a tag name unique within a repository but not across repositories', function () {
    $one = Package::factory()->create(['type' => PackageType::Docker]);
    $two = Package::factory()->create(['type' => PackageType::Docker]);
    $digest = 'sha256:'.str_repeat('c', 64);

    // Same digest in both repositories — this is the shape a global digest key could not
    // have handled at all; a per-package foreign key does not need to care.
    $manifestOne = OciManifest::create([
        'package_id' => $one->id,
        'digest' => $digest,
        'media_type' => 'application/vnd.oci.image.manifest.v1+json',
        'payload' => '{"schemaVersion":2}',
        'size' => 19,
    ]);
    $manifestTwo = OciManifest::create([
        'package_id' => $two->id,
        'digest' => $digest,
        'media_type' => 'application/vnd.oci.image.manifest.v1+json',
        'payload' => '{"schemaVersion":2}',
        'size' => 19,
    ]);

    OciTag::create(['package_id' => $one->id, 'name' => 'latest', 'manifest_id' => $manifestOne->id]);
    OciTag::create(['package_id' => $two->id, 'name' => 'latest', 'manifest_id' => $manifestTwo->id]);

    expect(fn () => OciTag::create(['package_id' => $one->id, 'name' => 'latest', 'manifest_id' => $manifestOne->id]))
        ->toThrow(QueryException::class);
});

it('stores the manifest payload verbatim', function () {
    // The digest is the hash of these exact bytes. Re-serialising parsed JSON would change
    // the digest and break every reference to the manifest.
    $payload = '{"schemaVersion":2,  "mediaType":"application/vnd.oci.image.manifest.v1+json"}';
    $package = Package::factory()->create(['type' => PackageType::Docker]);

    $manifest = OciManifest::create([
        'package_id' => $package->id,
        'digest' => 'sha256:'.hash('sha256', $payload),
        'media_type' => 'application/vnd.oci.image.manifest.v1+json',
        'payload' => $payload,
        'size' => strlen($payload),
    ]);

    expect($manifest->fresh()->payload)->toBe($payload);
});

it('resolves each tag to its own repositorys manifest under eager loading, even with a shared digest', function () {
    $one = Package::factory()->create(['type' => PackageType::Docker]);
    $two = Package::factory()->create(['type' => PackageType::Docker]);
    $digest = 'sha256:'.str_repeat('d', 64);

    // Same bytes pushed to two different repositories: the case a digest-keyed relation
    // could not survive, because eager loading resolves each parent's relation with one
    // shared query rather than one query per instance — there is no per-row `$this` to
    // smuggle a package_id constraint through.
    //
    // This asserts nothing beyond stock Eloquent belongsTo/hasMany behaviour today — with
    // a real foreign key there is no per-relation clause left to break, so this test
    // cannot go red on its own the way the composite-key test above can. Its job is to be
    // a tripwire: if a future change reintroduces a digest-keyed `manifest()`/`tags()`
    // pair (the brief's original, broken shape), this is the test that goes red for it.
    // Keep it even though it looks redundant next to the direct-access test.
    $manifestOne = OciManifest::create([
        'package_id' => $one->id,
        'digest' => $digest,
        'media_type' => 'application/vnd.oci.image.manifest.v1+json',
        'payload' => '{"schemaVersion":2}',
        'size' => 19,
    ]);
    $manifestTwo = OciManifest::create([
        'package_id' => $two->id,
        'digest' => $digest,
        'media_type' => 'application/vnd.oci.image.manifest.v1+json',
        'payload' => '{"schemaVersion":2}',
        'size' => 19,
    ]);

    OciTag::create(['package_id' => $one->id, 'name' => 'v1', 'manifest_id' => $manifestOne->id]);
    OciTag::create(['package_id' => $two->id, 'name' => 'v1', 'manifest_id' => $manifestTwo->id]);

    $packages = Package::with('ociTags.manifest')
        ->whereIn('id', [$one->id, $two->id])
        ->get()
        ->keyBy('id');

    expect($packages[$one->id]->ociTags->first()->manifest->is($manifestOne))->toBeTrue()
        ->and($packages[$two->id]->ociTags->first()->manifest->is($manifestTwo))->toBeTrue();
});

it('refuses a tag whose package_id disagrees with its manifest_id\'s repository', function () {
    $home = Package::factory()->create(['type' => PackageType::Docker]);
    $other = Package::factory()->create(['type' => PackageType::Docker]);

    $manifest = OciManifest::create([
        'package_id' => $home->id,
        'digest' => 'sha256:'.str_repeat('e', 64),
        'media_type' => 'application/vnd.oci.image.manifest.v1+json',
        'payload' => '{"schemaVersion":2}',
        'size' => 19,
    ]);

    // package_id names $other's repository; manifest_id names a manifest that actually
    // belongs to $home. A single-column FK on manifest_id alone would accept this row —
    // the composite FK on (manifest_id, package_id) against oci_manifests(id, package_id)
    // is what refuses it, because no oci_manifests row has this (id, package_id) pair.
    expect(fn () => OciTag::create([
        'package_id' => $other->id,
        'name' => 'latest',
        'manifest_id' => $manifest->id,
    ]))->toThrow(QueryException::class);
});
