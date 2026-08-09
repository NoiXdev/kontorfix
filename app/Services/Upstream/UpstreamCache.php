<?php

namespace App\Services\Upstream;

use App\Models\Upstream;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
     * How recently an artifact may have been written and still be safe to evict.
     *
     * Without a floor, a burst of cold artifacts evicts each other in a loop: every request
     * caches, is immediately evicted by the next, and the cache degrades into a write
     * amplifier — the opposite of what reclamation is for. The floor also keeps eviction
     * away from a `.part` staging file another worker is still filling.
     */
    private const EVICTION_MIN_AGE = 3600;

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

    /**
     * Whether the artifact is on the disk right now.
     *
     * Impure, and the analyser has to be told: asking twice in one request is exactly what
     * the fetch lock in ProxyDownloadController does, because the answer changes when the
     * request that held the lock fills the cache. Treated as pure, the second look is
     * "always false" and the collapse is optimised away.
     *
     * @phpstan-impure
     */
    public function hasArtifact(string $path): bool
    {
        return Storage::disk('artifacts')->exists($path);
    }

    /**
     * Makes room for $needed bytes by deleting the oldest proxy artifacts, and reports
     * whether the budget can now take them.
     *
     * A budget that is merely *reached* used to be a one-way wall: `putArtifact()` and
     * `relayArtifact()` both declined the local copy and kept serving, which is the right
     * call for that one request — but `hasArtifact()` then stayed false for that coordinate
     * forever, so every later request for it was another full upstream fetch and another
     * full relay. Nothing brought the cache back under budget except the daily prune, whose
     * horizon is `upstream_cache_prune_days` (30). So a cache that filled once put the whole
     * proxy into pass-through for a month, with no request budget in front of it — the byte
     * budget bounded disk while leaving the network, CPU and worker cost unbounded.
     *
     * A cache is supposed to evict. Oldest-written first, which is an approximation of
     * least-recently-used: the artifacts disk gives no reliable access time (relatime is off
     * on most container volumes), and the alternative — touching every artifact on every
     * download — buys a better ordering at the cost of a write per read. Stated rather than
     * implied, because it means a long-lived hot artifact can be evicted and re-fetched once.
     *
     * Two refusals, both deliberate:
     *
     *  - nothing written within EVICTION_MIN_AGE is a candidate, so a burst cannot evict
     *    itself in a loop and an in-flight write is never reclaimed underneath its writer;
     *  - an artifact larger than the whole budget frees the entire cache and still would not
     *    fit, so it is declined without deleting anything.
     *
     * Returns false only to decline *caching*. No caller may turn that into a refusal to
     * serve — see relayArtifact().
     */
    public function reclaim(int $needed): bool
    {
        $budget = (int) config('kontorfix.upstream_cache_max_bytes', 0);
        if ($budget <= 0) {
            return true;
        }

        $used = $this->usedBytes();
        if ($used + $needed <= $budget) {
            return true;
        }

        if ($needed > $budget) {
            return false;
        }

        $disk = Storage::disk('artifacts');
        $cutoff = now()->subSeconds(self::EVICTION_MIN_AGE)->getTimestamp();

        /** @var list<array{path: string, mtime: int, size: int}> $candidates */
        $candidates = [];
        foreach ($disk->allFiles(self::PROXY_PREFIX) as $file) {
            if (str_ends_with($file, '.part')) {
                continue;
            }

            $mtime = $disk->lastModified($file);
            if ($mtime >= $cutoff) {
                continue;
            }

            $candidates[] = ['path' => $file, 'mtime' => $mtime, 'size' => $disk->size($file)];
        }

        usort($candidates, fn (array $a, array $b): int => $a['mtime'] <=> $b['mtime']);

        $freed = 0;
        foreach ($candidates as $candidate) {
            if ($used - $freed + $needed <= $budget) {
                break;
            }

            $disk->delete($candidate['path']);
            $freed += $candidate['size'];
        }

        if ($freed > 0) {
            Cache::decrement(self::USAGE_KEY, $freed);
            Log::info('Upstream artifact cache evicted to make room.', [
                'freed' => $freed,
                'needed' => $needed,
                'budget' => $budget,
            ]);
        }

        return $used - $freed + $needed <= $budget;
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

        if (! $this->reclaim($size)) {
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
     * Three properties this deliberately keeps:
     *
     *  - the client is always served. Exceeding the cap stops the *caching*, never the
     *    download — a storage policy must not turn into "this package cannot be installed".
     *  - memory stays constant. The local copy exists only while it is still a cache
     *    candidate; the moment the cap is passed it is dropped and the remainder is
     *    relayed straight through, so nothing larger than the cap is ever held anywhere.
     *  - only a transfer known to be complete is committed. See isTruncated(): what arrived
     *    short is relayed to this one caller and then dropped, never written to the final
     *    key, because `hasArtifact()` would report a hit for it forever afterwards.
     *
     * @param  resource  $source
     * @param  int|null  $expectedBytes  the upstream's Content-Length, when it declared one
     */
    public function relayArtifact($source, string $path, ?int $expectedBytes = null): int
    {
        $maxArtifact = (int) config('kontorfix.upstream_cache_max_artifact_bytes', 0);
        $budget = (int) config('kontorfix.upstream_cache_max_bytes', 0);

        // No room at all: relay without ever opening a local copy. The size is not known
        // yet, so this only asks whether the cache can be brought under its budget at all;
        // the exact fit is settled below, once the artifact has arrived.
        $local = ($budget > 0 && $this->usedBytes() >= $budget && ! $this->reclaim(1)) ? null : tmpfile();
        $size = 0;
        $readFailed = false;
        $reachedEof = false;
        $timedOut = false;

        try {
            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);

                // A read error is not an end of file, and neither is an empty read on a
                // stream that has not reached EOF — under the StreamHandler that is exactly
                // what a stalled socket looks like once the read timeout elapses.
                if ($chunk === false || $chunk === '') {
                    $readFailed = $chunk === false;
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

            $reachedEof = feof($source);
            // PHPStan's stub declares `timed_out` as always present; it is not. Socket
            // streams carry it, php://temp and plain files do not (verified on this PHP),
            // so the coalesce is load-bearing and must survive the analyser.
            // @phpstan-ignore nullCoalesce.offset
            $timedOut = (bool) (stream_get_meta_data($source)['timed_out'] ?? false);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }

        if ($this->isTruncated($path, $size, $expectedBytes, $readFailed, $reachedEof, $timedOut)) {
            if (is_resource($local)) {
                fclose($local);
            }

            return $size;
        }

        if ($local === null) {
            return $size;
        }

        try {
            if (! $this->reclaim($size)) {
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
     * Whether the relay ended before the upstream body did — and therefore must not be
     * committed to the cache key.
     *
     * The buffered read this streaming relay replaced got this for free: a body short of
     * its Content-Length is curl error 18, Guzzle raised, and nothing was cached. Streaming
     * has to ask, because a reset, a truncated body and a socket stall all look like a
     * clean EOF to a loop that only tests `fread()`. Three independent signals, because no
     * single one covers every upstream:
     *
     *  - `fread()` returned false: a read error, never an end of file. Under the
     *    StreamHandler — the shipped configuration, since `allow_url_fopen` is on by default
     *    and `['stream' => true]` then routes there — `timeout(30)` is a *read* timeout, and
     *    a socket that hits it reads false with `timed_out` set.
     *  - the loop left the body without `feof()`: an empty read on a stream that still has
     *    more to come. Together with the case above this is the only signal available for a
     *    chunked upstream, which declares no length at all.
     *  - the byte count disagrees with the declared Content-Length. Exact where present,
     *    and it also catches an over-long body.
     *
     * On redundancy, stated rather than hidden: on every stream state reproducible here,
     * `$readFailed` implies `! $reachedEof`, so removing it from the decision kills no test.
     * It is kept because it is the signal the failure actually has (and it is reported
     * separately in the log context, which is what lets an operator tell a resetting
     * upstream from a merely slow one), not because the decision needs it. `timed_out` is
     * recorded for the same reason and for the same reason is not a decision term either.
     *
     * Deliberately NOT a signal: the absence of a Content-Length. Chunked transfer encoding
     * is legitimate and common, and refusing to cache it wholesale would turn every proxied
     * install on such an upstream into a fresh upstream fetch.
     */
    private function isTruncated(
        string $path,
        int $size,
        ?int $expectedBytes,
        bool $readFailed,
        bool $reachedEof,
        bool $timedOut,
    ): bool {
        $lengthMismatch = $expectedBytes !== null && $size !== $expectedBytes;

        if (! $readFailed && $reachedEof && ! $lengthMismatch) {
            return false;
        }

        // Nothing was logged when the relay ended short, so a poisoned entry left no trace
        // at all. The operator needs to be able to tell a flaky upstream from a quiet one.
        Log::warning('Upstream artifact relay ended truncated; not caching.', [
            'path' => $path,
            'received' => $size,
            'expected' => $expectedBytes,
            'read_failed' => $readFailed,
            'reached_eof' => $reachedEof,
            'timed_out' => $timedOut,
        ]);

        return true;
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
