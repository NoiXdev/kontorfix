<?php

namespace App\Support\Oci;

/**
 * The one parser of a manifest payload's references, shared by the pull-path blob gate
 * (BlobController) and the sweeper (OciReachability).
 *
 * One class because the two callers ask about the same bytes and would otherwise each carry
 * their own reader — two readers that agree until somebody edits one. They ask DIFFERENTLY,
 * though, which is why there are two methods rather than one list: the gate only needs "is
 * this digest named at all", while the sweeper has to tell a blob digest (config, layers)
 * from a child manifest digest (an index's `manifests`), because the first names an
 * oci_blobs row and the second an oci_manifests row.
 *
 * The payload is opaque JSON by design — ManifestStore stores it byte for byte because the
 * digest is its hash — so everything here is defensive: an unparseable or unexpected shape
 * yields an empty list rather than an exception. For a manifest this registry accepted that
 * shape cannot occur (ManifestController decodes the payload before it is ever stored);
 * what the guards cover is a row a restore, a seeder or a hand edit produced.
 *
 * A `subject` back-pointer (the referrers/cosign shape) is deliberately not read: it points
 * FROM a signature manifest TO the manifest it signs, so following it keeps nothing alive
 * that is not already reachable from its own tag — cosign tags its signature manifests.
 */
final class ManifestReferences
{
    /**
     * The blobs this manifest names directly: its config and every layer.
     *
     * @return list<string>
     */
    public static function blobDigests(string $payload): array
    {
        $decoded = self::decode($payload);

        $digests = [];

        $config = $decoded['config']['digest'] ?? null;
        if (is_string($config)) {
            $digests[] = $config;
        }

        return [...$digests, ...self::digestsIn($decoded, 'layers')];
    }

    /**
     * The per-platform child manifests an index names — OciManifest rows of their own,
     * pushed by digest before the index (see referencedByPackage()'s account in
     * BlobController). Named childManifestDigests because that is the name the gate's
     * docblock has referred to since Plan A.
     *
     * @return list<string>
     */
    public static function childManifestDigests(string $payload): array
    {
        return self::digestsIn(self::decode($payload), 'manifests');
    }

    /** @return array<string, mixed> */
    private static function decode(string $payload): array
    {
        $decoded = json_decode($payload, true);

        // is_array alone is not enough: a JSON list decodes to a PHP array too, and
        // `$list['config']` on one is an undefined-key read rather than a miss.
        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return list<string>
     */
    private static function digestsIn(array $decoded, string $key): array
    {
        $entries = $decoded[$key] ?? null;

        $digests = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            $digest = is_array($entry) ? ($entry['digest'] ?? null) : null;

            if (is_string($digest)) {
                $digests[] = $digest;
            }
        }

        return $digests;
    }
}
