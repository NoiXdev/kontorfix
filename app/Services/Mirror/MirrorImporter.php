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
            throw MirrorSyncFailed::because("Artefakt konnte nicht von der Quelle geladen werden: {$url}{$suffix}");
        }
        if ($fetched === null) {
            throw MirrorSyncFailed::because("Artefakt bei der Quelle nicht gefunden: {$url}");
        }

        $source = $fetched['stream'];
        $declaredLength = $fetched['length'];

        if ($declaredLength !== null && $declaredLength > $maxBytes) {
            fclose($source);
            throw MirrorSyncFailed::because("Artefakt überschreitet das erlaubte Größenlimit von {$maxBytes} Byte: {$url}");
        }

        $local = tmpfile();
        if ($local === false) {
            fclose($source);
            throw MirrorSyncFailed::because("Artefakt konnte nicht zwischengespeichert werden: {$url}");
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
                    throw MirrorSyncFailed::because("Artefakt überschreitet das erlaubte Größenlimit von {$maxBytes} Byte: {$url}");
                }

                hash_update($sha1Context, $chunk);
                hash_update($sha256Context, $chunk);
                fwrite($local, $chunk);
            }

            $gotSha1 = hash_final($sha1Context);
            $gotSha256 = hash_final($sha256Context);

            if ($sha1 !== null && ! hash_equals(strtolower($sha1), $gotSha1)) {
                throw MirrorSyncFailed::because("Prüfsumme (sha1) des Artefakts stimmt nicht überein: {$url}");
            }
            if ($sha256 !== null && ! hash_equals(strtolower($sha256), $gotSha256)) {
                throw MirrorSyncFailed::because("Prüfsumme (sha256) des Artefakts stimmt nicht überein: {$url}");
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
