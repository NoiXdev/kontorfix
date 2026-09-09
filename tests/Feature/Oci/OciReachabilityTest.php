<?php

use App\Models\OciManifest;
use App\Models\OciTag;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Oci\Sweeper\OciReachability;

/**
 * A manifest whose digest really is the hash of its payload, as ManifestStore guarantees
 * for every row it writes — reachability resolves index children by digest, so a fixture
 * with a random digest would make the walk miss for a reason no real row can have.
 *
 * @param  array<string, mixed>  $payload
 */
function reachabilityManifest(Package $package, array $payload): OciManifest
{
    $bytes = json_encode($payload, JSON_THROW_ON_ERROR);

    return OciManifest::factory()->for($package)->create([
        'digest' => 'sha256:'.hash('sha256', $bytes),
        'payload' => $bytes,
        'size' => strlen($bytes),
    ]);
}

function reachabilityTag(Package $package, OciManifest $manifest, string $name = 'latest'): OciTag
{
    return OciTag::factory()->create([
        'package_id' => $package->id,
        'name' => $name,
        'manifest_id' => $manifest->id,
    ]);
}

it('reaches the config and layers of a tagged manifest', function () {
    $package = Package::factory()->docker()->create();
    $manifest = reachabilityManifest($package, [
        'config' => ['digest' => 'sha256:cfg'],
        'layers' => [['digest' => 'sha256:lay']],
    ]);
    reachabilityTag($package, $manifest);

    $set = app(OciReachability::class)->forOrganization((string) $package->organization_id);

    expect($set->hasManifest($manifest->id))->toBeTrue()
        ->and($set->hasBlob('sha256:cfg'))->toBeTrue()
        ->and($set->hasBlob('sha256:lay'))->toBeTrue();
});

it("reaches an index's children and their layers", function () {
    $package = Package::factory()->docker()->create();
    $child = reachabilityManifest($package, ['config' => ['digest' => 'sha256:cfg'], 'layers' => [['digest' => 'sha256:arm']]]);
    $index = reachabilityManifest($package, ['manifests' => [['digest' => $child->digest]]]);
    reachabilityTag($package, $index);

    $set = app(OciReachability::class)->forOrganization((string) $package->organization_id);

    expect($set->hasManifest($child->id))->toBeTrue()
        ->and($set->hasBlob('sha256:arm'))->toBeTrue();
});

it('reaches through an index of indexes', function () {
    $package = Package::factory()->docker()->create();
    $leaf = reachabilityManifest($package, ['layers' => [['digest' => 'sha256:leaf']]]);
    $inner = reachabilityManifest($package, ['manifests' => [['digest' => $leaf->digest]]]);
    $outer = reachabilityManifest($package, ['manifests' => [['digest' => $inner->digest]]]);
    reachabilityTag($package, $outer);

    // A one-level walk would answer false here, and the sweeper would delete a real layer.
    expect(app(OciReachability::class)->forOrganization((string) $package->organization_id)->hasBlob('sha256:leaf'))->toBeTrue();
});

it('does not reach an untagged manifest or its layers', function () {
    $package = Package::factory()->docker()->create();
    $orphan = reachabilityManifest($package, ['layers' => [['digest' => 'sha256:orphan']]]);

    $set = app(OciReachability::class)->forOrganization((string) $package->organization_id);

    expect($set->hasManifest($orphan->id))->toBeFalse()
        ->and($set->hasBlob('sha256:orphan'))->toBeFalse();
});

it('reaches a blob through ANY package of the organization', function () {
    // The deduplication claim. oci_blobs is unique on (organization_id, digest) and carries
    // no package dimension at all, so a sweep scoped per package would delete the shared
    // base layer of two images the moment one of them lost its tags.
    $organization = Organization::factory()->create();
    $a = Package::factory()->docker()->for($organization)->create(['name' => 'a']);
    $b = Package::factory()->docker()->for($organization)->create(['name' => 'b']);

    reachabilityManifest($a, ['layers' => [['digest' => 'sha256:base']]]);  // untagged: a lost its tags
    reachabilityTag($b, reachabilityManifest($b, ['layers' => [['digest' => 'sha256:base']]]));

    expect(app(OciReachability::class)->forOrganization((string) $organization->id)->hasBlob('sha256:base'))->toBeTrue();
});

it("does not reach another organization's blob", function () {
    $mine = Package::factory()->docker()->create();
    $theirs = Package::factory()->docker()->create();
    reachabilityTag($theirs, reachabilityManifest($theirs, ['layers' => [['digest' => 'sha256:fremd']]]));

    expect(app(OciReachability::class)->forOrganization((string) $mine->organization_id)->hasBlob('sha256:fremd'))->toBeFalse();
});

it('resolves an index child within its own repository only', function () {
    // A digest is unique only WITHIN a repository, so a child digest must be resolved
    // against the index's own package. Two repositories of one organization holding the
    // same digest is the ordinary case, not an edge one.
    $organization = Organization::factory()->create();
    $a = Package::factory()->docker()->for($organization)->create(['name' => 'a']);
    $b = Package::factory()->docker()->for($organization)->create(['name' => 'b']);

    // B's copy FIRST, deliberately: a lookup that resolves a child by digest alone takes
    // the first row holding it, and with B first that is the WRONG repository's manifest.
    // With A first, the same broken lookup happens to find the right row and this test
    // proves nothing — the "fixture indistinguishable under both readings" trap.
    $childInB = reachabilityManifest($b, ['layers' => [['digest' => 'sha256:in-a']]]);
    $childInA = reachabilityManifest($a, ['layers' => [['digest' => 'sha256:in-a']]]);
    $index = reachabilityManifest($a, ['manifests' => [['digest' => $childInA->digest]]]);
    reachabilityTag($a, $index);

    $set = app(OciReachability::class)->forOrganization((string) $organization->id);

    expect($set->hasManifest($childInA->id))->toBeTrue()
        ->and($set->hasManifest($childInB->id))->toBeFalse();
});
