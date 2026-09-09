<?php

namespace App\Services\Mirror;

use App\Enums\PackageType;
use App\Exceptions\MirrorSyncFailed;
use App\Exceptions\UpstreamException;
use App\Models\MirrorSource;
use App\Models\Package;
use App\Services\Upstream\UpstreamClient;
use App\Services\Upstream\UpstreamEndpoint;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/**
 * Imports versions into a mirror-sourced package (Package::isMirrorSourced()) by talking to
 * its assigned MirrorSource — the mirror counterpart to GitSourceImporter, which does the
 * same job for a git-sourced package. Dispatches to a per-type collaborator (Composer, npm
 * and Python) and exposes the streaming artifact download every one of those collaborators
 * needs, so the cap enforcement, checksum verification and atomic staging→move live in
 * exactly one place.
 *
 * Every failure — metadata not found, an oversize or checksum-mismatched artifact, an
 * upstream error — surfaces as MirrorSyncFailed with a German message, never a lower-level
 * exception, so a caller can write it straight to Package::sync_error.
 */
class MirrorImporter
{
    /** Read in chunks while streaming an artifact to disk — the whole memory footprint. */
    private const CHUNK_BYTES = 262144;

    public function __construct(
        private readonly UpstreamClient $client,
    ) {}

    public function import(Package $package, MirrorSource $source): void
    {
        $this->sweepOrphanedStaging($package);

        match ($package->type) {
            PackageType::Composer => (new ComposerMirrorImport($this, $this->client))->import($package, $source),
            PackageType::Npm => (new NpmMirrorImport($this, $this->client))->import($package, $source),
            PackageType::Python => (new PythonMirrorImport($this, $this->client))->import($package, $source),
            // A Docker repository is never mirror-sourced (PackageSourceMode::allowedFor()
            // excludes Mirror for it) — this arm exists only to keep the match exhaustive
            // and fail loudly if that invariant is ever broken upstream.
            PackageType::Docker => throw new LogicException('Docker packages are never mirror-sourced; the caller must guard this before calling import().'),
        };
    }

    /**
     * Deletes leftover staging files in $package's own artifact directories before the real
     * import runs — the fix for a hard worker kill (a timeout's SIGALRM) landing between
     * fetchArtifact()'s writeStream() and move(), or, for npm, between its own staging write
     * (NpmMirrorImport::stagingPath()) and the move past verifyIntegrity(). Neither failure
     * mode ever throws from inside this class, so nothing else on the artifacts disk ever
     * revisits that file again.
     *
     * Safe to run unconditionally at the start of every sync: SyncMirrorPackage's
     * WithoutOverlapping($package->id) guarantees this is the only import for $package
     * running at any given moment, so any staging file already sitting in one of its three
     * possible artifact directories (only one of which a real package ever populates, by
     * type) cannot belong to an in-flight sync — it can only be garbage a previous run left
     * behind before ever reaching its own move(). Sweeping all three unconditionally (rather
     * than branching on $package->type) costs nothing extra: the other two directories are
     * simply empty/absent for that package.
     *
     * Matches exactly the two staging shapes this class and NpmMirrorImport ever write —
     * `.` + the final filename + `.` + a random suffix + `.part` (fetchArtifact()) or
     * `.integrity-check` (NpmMirrorImport::stagingPath()) — never a real artifact, which is
     * never dot-prefixed.
     */
    private function sweepOrphanedStaging(Package $package): void
    {
        $disk = Storage::disk('artifacts');

        foreach (['dists', 'tarballs', 'pypi'] as $prefix) {
            foreach ($disk->files("{$prefix}/{$package->id}") as $file) {
                $basename = basename($file);
                if (! str_starts_with($basename, '.')) {
                    continue;
                }
                if (str_ends_with($basename, '.part') || str_ends_with($basename, '.integrity-check')) {
                    $disk->delete($file);
                }
            }
        }
    }

    /**
     * Streams $url to the artifacts disk at $path (atomic .part staging→move), enforcing
     * $maxBytes and, when $sha256/$sha1 are given, verifying them — all while the bytes are
     * still arriving, so an oversize or corrupt artifact never sits fully buffered in PHP
     * memory and never reaches the final path. On a cap breach or a checksum mismatch
     * nothing is written to the artifacts disk (the local staging buffer is discarded) and
     * MirrorSyncFailed is thrown; the caller's row-only-after-artifact rule then means no
     * PackageVersion is created for it.
     *
     * @return array{0: int, 1: string, 2: string} [size, sha1, sha256]
     */
    public function fetchArtifact(UpstreamEndpoint $endpoint, string $url, string $path, int $maxBytes, ?string $sha256 = null, ?string $sha1 = null): array
    {
        try {
            $fetched = $this->client->getStream($endpoint, $url);
        } catch (UpstreamException $e) {
            // $e->status() is a language-neutral fact (an HTTP status code, or null for a
            // transport-level refusal); $e->getMessage() is English prose and MUST NOT be
            // spliced in here — this message is shown to operators as Package::sync_error
            // and is otherwise entirely German.
            $suffix = $e->status() !== null ? " (HTTP {$e->status()})" : '';
            throw MirrorSyncFailed::because("Artefakt konnte nicht von der Quelle geladen werden: {$url}{$suffix}.");
        }
        if ($fetched === null) {
            throw MirrorSyncFailed::because("Artefakt bei der Quelle nicht gefunden: {$url}.");
        }

        $source = $fetched['stream'];
        $declaredLength = $fetched['length'];

        if ($declaredLength !== null && $declaredLength > $maxBytes) {
            fclose($source);
            throw MirrorSyncFailed::because("Artefakt überschreitet das erlaubte Größenlimit von {$maxBytes} Byte: {$url}.");
        }

        $local = tmpfile();
        if ($local === false) {
            fclose($source);
            throw MirrorSyncFailed::because("Artefakt konnte nicht zwischengespeichert werden: {$url}.");
        }

        $sha1Context = hash_init('sha1');
        $sha256Context = hash_init('sha256');
        $size = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $size += strlen($chunk);
                if ($size > $maxBytes) {
                    throw MirrorSyncFailed::because("Artefakt überschreitet das erlaubte Größenlimit von {$maxBytes} Byte: {$url}.");
                }

                hash_update($sha1Context, $chunk);
                hash_update($sha256Context, $chunk);
                fwrite($local, $chunk);
            }

            // The declared Content-Length is checked twice: upfront (above, a cheap refusal
            // before a single byte is read) and here, against what was ACTUALLY received.
            // Without this second check, a connection that drops mid-transfer — the stream
            // simply stops producing bytes, see UpstreamClient::getStream()'s own doc comment
            // on why libcurl/Guzzle never raises for that — would fall through to a truncated
            // artifact being staged and moved into place below as if it were complete. Worse
            // than a one-off corrupt download: for a checksum-less feed (a Composer p2 entry
            // with no shasum, or a PyPI file with no declared sha256 — both real, supported
            // shapes this importer accepts) nothing else would ever catch it, and the
            // idempotency skip every per-type importer applies (same size as the stored
            // dist_size/PythonDist::size ⇒ already imported, don't re-fetch) would then treat
            // the truncated file as permanently correct — no resync ever heals it.
            if ($declaredLength !== null && $size !== $declaredLength) {
                throw MirrorSyncFailed::because("Artefakt wurde unvollständig übertragen (erwartet {$declaredLength} Byte, erhalten {$size} Byte): {$url}.");
            }

            $gotSha1 = hash_final($sha1Context);
            $gotSha256 = hash_final($sha256Context);

            if ($sha1 !== null && ! hash_equals(strtolower($sha1), $gotSha1)) {
                throw MirrorSyncFailed::because("Prüfsumme (sha1) des Artefakts stimmt nicht überein: {$url}.");
            }
            if ($sha256 !== null && ! hash_equals(strtolower($sha256), $gotSha256)) {
                throw MirrorSyncFailed::because("Prüfsumme (sha256) des Artefakts stimmt nicht überein: {$url}.");
            }

            rewind($local);
            $disk = Storage::disk('artifacts');
            $staging = dirname($path).'/.'.basename($path).'.'.Str::random(8).'.part';
            $disk->writeStream($staging, $local);
            $disk->move($staging, $path);

            return [$size, $gotSha1, $gotSha256];
        } finally {
            fclose($source);
            if (is_resource($local)) {
                fclose($local);
            }
        }
    }
}
