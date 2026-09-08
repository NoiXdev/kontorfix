<?php

namespace App\Support\Oci;

/**
 * What one sweep did — or, from OciSweeper::pending(), would do. In pending()'s answer the
 * "removed" figures are the would-be work and blobsRemoved stays 0; the field names read as
 * a real run's because the admin page and the activity log share this shape.
 */
final readonly class SweepReport
{
    public function __construct(
        public int $manifestsRemoved,
        public int $blobsRemoved,
        public int $bytesReclaimed,
        public int $blobsHeldByGrace,
        public int $blobsRemaining,
        public int $uploadsRemoved,
        public int $repositoriesRemoved,
    ) {}

    /** Whether anything at all was (or would be) removed — what gates the audit entry. */
    public function hasWork(): bool
    {
        return $this->manifestsRemoved + $this->blobsRemoved + $this->uploadsRemoved + $this->repositoriesRemoved > 0;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'manifests_removed' => $this->manifestsRemoved,
            'blobs_removed' => $this->blobsRemoved,
            'bytes_reclaimed' => $this->bytesReclaimed,
            'blobs_held_by_grace' => $this->blobsHeldByGrace,
            'blobs_remaining' => $this->blobsRemaining,
            'uploads_removed' => $this->uploadsRemoved,
            'repositories_removed' => $this->repositoriesRemoved,
        ];
    }
}
