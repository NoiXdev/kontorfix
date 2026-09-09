<?php

use App\Models\Package;
use App\Services\Oci\ManifestStore;

function pushedAtManifestPayload(string $layerDigest): string
{
    return json_encode([
        'schemaVersion' => 2,
        'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
        'config' => ['digest' => 'sha256:'.str_repeat('c', 64), 'size' => 7],
        'layers' => [['digest' => $layerDigest, 'size' => 11]],
    ], JSON_THROW_ON_ERROR);
}

it('moves pushed_at on a re-push at an unchanged manifest and leaves updated_at alone', function () {
    $package = Package::factory()->docker()->create();
    $payload = pushedAtManifestPayload('sha256:'.str_repeat('a', 64));

    // travelTo() is what makes the two timestamps comparable at all: without it both
    // writes land in the same second and the updated_at assertion passes trivially.
    $this->travelTo('2026-09-01 10:00:00');
    app(ManifestStore::class)->put($package, 'latest', $payload, 'application/vnd.oci.image.manifest.v1+json');

    $first = $package->ociTags()->where('name', 'latest')->sole();
    expect($first->pushed_at->toDateTimeString())->toBe('2026-09-01 10:00:00');

    // Same bytes, same digest, so updateOrCreate finds nothing to change on the tag.
    $this->travelTo('2026-09-02 10:00:00');
    app(ManifestStore::class)->put($package, 'latest', $payload, 'application/vnd.oci.image.manifest.v1+json');

    $second = $package->ociTags()->where('name', 'latest')->sole();

    expect($second->pushed_at->toDateTimeString())->toBe('2026-09-02 10:00:00')
        // THE point of the column. If this is the assertion that fails, the stamp was
        // written through an ordinary save and Eloquent bumped updated_at with it —
        // which silently reorders the tag table scopeInPullOrder() sorts.
        ->and($second->updated_at->toDateTimeString())->toBe($first->updated_at->toDateTimeString());
});

it('moves both when the tag is actually re-pointed', function () {
    $package = Package::factory()->docker()->create();

    $this->travelTo('2026-09-01 10:00:00');
    app(ManifestStore::class)->put($package, 'latest', pushedAtManifestPayload('sha256:'.str_repeat('a', 64)), 'application/vnd.oci.image.manifest.v1+json');
    $first = $package->ociTags()->where('name', 'latest')->sole();

    $this->travelTo('2026-09-02 10:00:00');
    app(ManifestStore::class)->put($package, 'latest', pushedAtManifestPayload('sha256:'.str_repeat('b', 64)), 'application/vnd.oci.image.manifest.v1+json');
    $second = $package->ociTags()->where('name', 'latest')->sole();

    expect($second->manifest_id)->not->toBe($first->manifest_id)
        ->and($second->updated_at->toDateTimeString())->toBe('2026-09-02 10:00:00')
        ->and($second->pushed_at->toDateTimeString())->toBe('2026-09-02 10:00:00');
});

it('does not create a tag for a digest reference', function () {
    $package = Package::factory()->docker()->create();
    $payload = pushedAtManifestPayload('sha256:'.str_repeat('a', 64));
    $digest = 'sha256:'.hash('sha256', $payload);

    app(ManifestStore::class)->put($package, $digest, $payload, 'application/vnd.oci.image.manifest.v1+json');

    expect($package->ociTags()->count())->toBe(0);
});
