<?php

namespace App\Services\Scanner;

/**
 * What to scan, in the adapter's own terms.
 *
 * `$repository` is the PATH-ADDRESSED name (`{orgSlug}/{registrySlug}/{name}`) and never the
 * custom-domain form, because the adapter reaches us on the in-network address where path
 * addressing is the only form that resolves.
 */
final class ScanTarget
{
    public function __construct(
        public readonly string $repository,
        public readonly string $digest,
        public readonly ?string $tag = null,
    ) {}
}
