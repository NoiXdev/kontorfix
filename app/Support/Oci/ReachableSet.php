<?php

namespace App\Support\Oci;

/**
 * What is reachable in one organization. Array keys rather than lists, so the sweeper's
 * per-row check is a hash lookup — it runs once per blob row, and in_array over a list of
 * every reachable digest would make the sweep quadratic in a registry's size.
 */
final readonly class ReachableSet
{
    /**
     * @param  array<string, true>  $manifestIds
     * @param  array<string, true>  $blobDigests
     */
    public function __construct(
        private array $manifestIds,
        private array $blobDigests,
    ) {}

    public function hasManifest(string $id): bool
    {
        return isset($this->manifestIds[$id]);
    }

    public function hasBlob(string $digest): bool
    {
        return isset($this->blobDigests[$digest]);
    }
}
