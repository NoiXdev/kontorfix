<?php

namespace App\Services\Oci;

use App\Exceptions\OciException;

/**
 * The one place that knows the digest algorithm this registry accepts, and the one shape
 * of digest string it will ever compute, compare, or turn into a storage path.
 *
 * sha256 only: we cannot verify what we cannot compute, so an OCI client naming any other
 * algorithm (sha512, md5, …) is refused at the door rather than trusted.
 */
final class Digest
{
    private function __construct() {}

    /**
     * The only shape this registry accepts: `sha256:` plus exactly 64 lowercase hex
     * characters. Uppercase hex is refused too — it hashes to the same bytes, but it is
     * not the canonical form this registry stores or compares against, and accepting it
     * would mean normalising it somewhere before every comparison instead of never seeing
     * it at all.
     */
    public static function assertValid(string $digest): void
    {
        if (! preg_match('/^sha256:[a-f0-9]{64}$/', $digest)) {
            throw OciException::unsupported('Nur sha256-Digests werden unterstützt.');
        }
    }

    /** The sha256 digest of the given bytes, in this registry's canonical `sha256:…` form. */
    public static function of(string $bytes): string
    {
        return 'sha256:'.hash('sha256', $bytes);
    }

    /**
     * The content-addressed storage path for a blob, sharded by the first two hex
     * characters of its digest so a single organization's blob directory never ends up
     * with an unmanageably flat fan-out of files.
     */
    public static function pathFor(string $organizationId, string $digest): string
    {
        $hex = substr($digest, strlen('sha256:'));
        $shard = substr($hex, 0, 2);

        return "docker/{$organizationId}/blobs/sha256/{$shard}/{$hex}";
    }
}
