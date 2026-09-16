<?php

namespace App\Services\Scanner;

/**
 * What to scan, in the adapter's own terms.
 *
 * `$repository` is the PATH-ADDRESSED name (`{orgSlug}/{registrySlug}/{name}`) and never the
 * custom-domain form, because the adapter reaches us on the in-network address where path
 * addressing is the only form that resolves.
 *
 * `$mediaType` is the manifest's OWN `media_type`, carried rather than assumed. The adapter
 * is told which format to expect at the digest it is about to pull, and this registry already
 * knows the answer: every `buildx`/BuildKit push — the modern default — writes
 * `application/vnd.oci.image.manifest.v1+json`, not the Docker schema 2 type. Null when the
 * manifest records none, in which case the adapter is told nothing rather than something
 * false; HarborAdapterScanner omits the field entirely.
 */
final class ScanTarget
{
    public function __construct(
        public readonly string $repository,
        public readonly string $digest,
        public readonly ?string $tag = null,
        public readonly ?string $mediaType = null,
    ) {}
}
