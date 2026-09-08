<?php

use App\Support\Oci\ManifestReferences;

it('reads the config and every layer of an image manifest', function () {
    $payload = json_encode([
        'config' => ['digest' => 'sha256:cc'],
        'layers' => [['digest' => 'sha256:l1'], ['digest' => 'sha256:l2']],
    ], JSON_THROW_ON_ERROR);

    expect(ManifestReferences::blobDigests($payload))->toBe(['sha256:cc', 'sha256:l1', 'sha256:l2'])
        ->and(ManifestReferences::childManifestDigests($payload))->toBe([]);
});

it("reads an index's children as manifests, never as blobs", function () {
    $payload = json_encode(['manifests' => [['digest' => 'sha256:m1'], ['digest' => 'sha256:m2']]], JSON_THROW_ON_ERROR);

    // THE distinction the sweeper needs and the blob gate does not: an index child is an
    // oci_manifests row, not an oci_blobs row. Treated as a blob digest it would mark
    // nothing reachable through the index, and the index's whole subtree would be garbage.
    expect(ManifestReferences::blobDigests($payload))->toBe([])
        ->and(ManifestReferences::childManifestDigests($payload))->toBe(['sha256:m1', 'sha256:m2']);
});

it('survives a payload that is not an object, a list, or JSON at all', function () {
    foreach (['not json', '[]', 'null', '"x"', '{"config": "nope"}', '{"layers": [1, 2]}', '{"layers": {"digest": "x"}}'] as $payload) {
        expect(ManifestReferences::blobDigests($payload))->toBe([])
            ->and(ManifestReferences::childManifestDigests($payload))->toBe([]);
    }
});

it('does not follow a referrers subject', function () {
    // Deliberate: `subject` points FROM a signature manifest TO the manifest it signs, so
    // following it keeps nothing alive that its own tag does not already keep alive — and
    // cosign tags its signature manifests, which is why the Referrers API could be a Plan A
    // non-goal in the first place.
    $payload = json_encode([
        'subject' => ['digest' => 'sha256:signed'],
        'layers' => [['digest' => 'sha256:l1']],
    ], JSON_THROW_ON_ERROR);

    expect(ManifestReferences::childManifestDigests($payload))->toBe([])
        ->and(ManifestReferences::blobDigests($payload))->toBe(['sha256:l1']);
});
