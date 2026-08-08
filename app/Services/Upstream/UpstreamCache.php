<?php

namespace App\Services\Upstream;

use App\Models\Upstream;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class UpstreamCache
{
    /** Every proxied artifact lives under this prefix on the artifacts disk. */
    private const PROXY_PREFIX = 'proxy';

    /** Memoised total size of the proxy tree. */
    private const USAGE_KEY = 'upstream-cache:proxy-bytes';

    /** How long before the total is recounted from the disk. */
    private const USAGE_TTL = 300;

    /** Read granularity while relaying an artifact — this is the whole memory footprint. */
    private const CHUNK_BYTES = 262144;

    /**
     * @return array<string, mixed>|null null on miss or expiry
     */
    public function getMetadata(Upstream $upstream, string $packageName): ?array
    {
        $ttl = (int) config('kontorfix.upstream_cache_ttl', 300);
        $row = $upstream->metadataCache()->where('package_name', $packageName)->first();

        if ($row === null || $row->fetched_at->lt(now()->subSeconds($ttl))) {
            return null;
        }

        return $row->payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function putMetadata(Upstream $upstream, string $packageName, array $payload): void
    {
        $upstream->metadataCache()->updateOrCreate(
            ['package_name' => $packageName],
            ['payload' => $payload, 'fetched_at' => now()],
        );
    }

    /**
     * Whether a value may be interpolated into a storage key as a single path segment.
     *
     * Flysystem's WhitespacePathNormalizer rewrites `\` to `/` BEFORE it collapses `..`,
     * so a backslash inside a client-supplied value is a directory separator in disguise:
     * a route constraint that merely excludes `/` does not keep the resulting key inside
     * the directory it was meant for. Both separators, every relative component and NUL
     * are refused outright rather than normalised away, so the decision cannot be undone
     * by a later normalisation pass — and an empty segment is refused too, because it
     * collapses into the surrounding slashes and shortens the path by one level.
     */
    public static function isSafeKeySegment(string $segment): bool
    {
        return $segment !== ''
            && $segment !== '.'
            && $segment !== '..'
            && ! str_contains($segment, '/')
            && ! str_contains($segment, '\\')
            && ! str_contains($segment, "\0");
    }

    public function hasArtifact(string $path): bool
    {
        return Storage::disk('artifacts')->exists($path);
    }

    /**
     * Caches a proxied artifact if it fits the budget, and reports whether it did.
     *
     * A false return is not an error: the caller still holds the bytes and serves them.
     * Declining to cache is the whole point of the limit — refusing to SERVE a package
     * because the cache is full would turn a disk-space policy into an outage.
     */
    public function putArtifact(string $path, string $bytes): bool
    {
        $size = strlen($bytes);

        $maxArtifact = (int) config('kontorfix.upstream_cache_max_artifact_bytes', 0);
        if ($maxArtifact > 0 && $size > $maxArtifact) {
            return false;
        }

        $budget = (int) config('kontorfix.upstream_cache_max_bytes', 0);
        if ($budget > 0 && $this->usedBytes() + $size > $budget) {
            return false;
        }

        // Atomic: staging -> move.
        $disk = Storage::disk('artifacts');
        $staging = $path.'.'.uniqid().'.part';
        $disk->put($staging, $bytes);
        $disk->move($staging, $path);

        Cache::increment(self::USAGE_KEY, $size);

        return true;
    }

    /**
     * Copies an upstream artifact to the caller's output, caching it on the way past when
     * it fits, and returns how many bytes went.
     *
     * putArtifact() takes the artifact as a string, which means the whole thing is in PHP
     * memory before its size is ever compared to the cap: on the shipped 128 M
     * memory_limit an oversize artifact exhausted the worker instead of being declined, so
     * the 100 MiB limit was unreachable by construction. Here the cap is applied as the
     * bytes arrive.
     *
     * Two properties this deliberately keeps:
     *
     *  - the client is always served. Exceeding the cap stops the *caching*, never the
     *    download — a storage policy must not turn into "this package cannot be installed".
     *  - memory stays constant. The local copy exists only while it is still a cache
     *    candidate; the moment the cap is passed it is dropped and the remainder is
     *    relayed straight through, so nothing larger than the cap is ever held anywhere.
     *
     * @param  resource  $source
     */
    public function relayArtifact($source, string $path): int
    {
        $maxArtifact = (int) config('kontorfix.upstream_cache_max_artifact_bytes', 0);
        $budget = (int) config('kontorfix.upstream_cache_max_bytes', 0);

        // No room at all: relay without ever opening a local copy.
        $local = ($budget > 0 && $this->usedBytes() >= $budget) ? null : tmpfile();
        $size = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $size += strlen($chunk);
                echo $chunk;

                if ($local === null) {
                    continue;
                }

                if ($maxArtifact > 0 && $size > $maxArtifact) {
                    fclose($local);
                    $local = null;

                    continue;
                }

                fwrite($local, $chunk);
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }

        if ($local === null) {
            return $size;
        }

        try {
            if ($budget > 0 && $this->usedBytes() + $size > $budget) {
                return $size;
            }

            rewind($local);

            // Atomic: staging -> move, so a concurrent reader never sees a torn file.
            $disk = Storage::disk('artifacts');
            $staging = $path.'.'.uniqid().'.part';
            $disk->writeStream($staging, $local);
            $disk->move($staging, $path);

            Cache::increment(self::USAGE_KEY, $size);
        } finally {
            if (is_resource($local)) {
                fclose($local);
            }
        }

        return $size;
    }

    /**
     * Deletes proxy artifacts untouched for more than $days, and returns how many went.
     * This is what brings a cache that has reached its budget back under it; without it
     * the budget would be a one-way wall the operator could only clear by hand.
     */
    public function pruneArtifacts(int $days): int
    {
        $disk = Storage::disk('artifacts');
        $cutoff = now()->subDays($days)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles(self::PROXY_PREFIX) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        Cache::forget(self::USAGE_KEY);

        return $deleted;
    }

    /**
     * The accounted byte total as recorded, or null when nothing is recorded — unlike
     * usedBytes() this never falls back to counting the tree. The difference between the
     * two is what exposes a container that shares the accounting (Redis) but not the
     * storage it accounts for.
     */
    public function recordedBytes(): ?int
    {
        $recorded = Cache::get(self::USAGE_KEY);

        return is_numeric($recorded) ? (int) $recorded : null;
    }

    /**
     * Bytes currently held by the proxy cache. Counting the tree is O(files), so the
     * total is memoised and kept current by incrementing it on write; the periodic
     * recount is what makes it self-correcting after a prune or an out-of-band deletion.
     */
    public function usedBytes(): int
    {
        return (int) Cache::remember(self::USAGE_KEY, self::USAGE_TTL, function (): int {
            $disk = Storage::disk('artifacts');
            $total = 0;

            foreach ($disk->allFiles(self::PROXY_PREFIX) as $file) {
                $total += $disk->size($file);
            }

            return $total;
        });
    }
}
